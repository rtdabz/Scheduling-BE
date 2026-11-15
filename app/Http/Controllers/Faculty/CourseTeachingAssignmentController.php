<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Department\CrossDepartmentApprovalGate;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CourseTeachingAssignmentController extends Controller
{
    public const OUTSIDE_PROGRAM_MESSAGE = 'Program Heads can only manage Course Assignment for their own program\'s courses.';

    public const OWN_DEPARTMENT_MESSAGE = 'Your department\'s courses are managed within your department, so they cannot be assigned to it through Course Assignment. Choose another college.';

    public const OWN_DEPARTMENT_PROGRAM_REQUIRED_MESSAGE = 'Within your own department, choose a specific program other than your own.';

    public const OWN_PROGRAM_MESSAGE = 'Courses cannot be assigned to your own program through Course Assignment. Choose another program or college.';

    private const RESTRICTED_ROLES = ['program_head', 'secretary', 'director'];

    public function __construct(
        private readonly SystemNotificationService $notifications,
        private readonly CrossDepartmentApprovalGate $approvalGate,
        private readonly ScheduleAuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);

        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        $programId = $request->user()?->role === 'program_head'
            ? ($request->user()?->program_id === null ? null : (int) $request->user()->program_id)
            : null;
        $activeSemester = $this->activeSemester();
        $activePeriod = self::pivotSemester($activeSemester?->semester);

        $curriculumIds = $this->activeCurriculumIds($departmentId, $programId);
        $courses = $this->departmentCourses($departmentId, $curriculumIds, $activePeriod);
        $instructorClasses = $this->classesWithInstructor($courses->pluck('id')->map('intval')->all());
        $sectionAssignments = $this->sectionAssignments(
            $courses->pluck('id')->map('intval')->values()->all(),
            $departmentId,
            $programId,
        );
        $incoming = Course::query()->with(['department', 'teachingDepartment', 'teachingProgram', 'teachingSourceProgram', 'program'])
            ->where('status', 'active')->delegatedTo($departmentId, $programId)
            ->when($activePeriod !== null, fn ($query) => $query->whereIn(
                'courses.id',
                $this->coursesPlacedInSemester($activePeriod),
            ))
            ->orderBy('course_code')->get()->map(fn (Course $course): array => $this->presentIncoming($course))->values();

        return response()->json([
            'current_department_id' => $departmentId,
            'cross_department_unlocked' => $this->approvalGate->isOpenFor($request->user()),
            'assignment_restrictions' => $this->targetRestrictions($request->user()),
            'has_active_curriculum' => $curriculumIds->isNotEmpty(),
            'active_semester' => $activeSemester === null ? null : [
                'id' => (int) $activeSemester->id,
                'academic_year' => $activeSemester->academic_year,
                'semester' => $activeSemester->semester,
            ],
            'departments' => Departments::query()
                ->orderBy('department_name')
                ->get(['id', 'department_code', 'department_name', 'logo']),
            'programs' => Program::query()
                ->orderBy('department_id')
                ->orderBy('code')
                ->get(['id', 'department_id', 'code', 'name', 'major']),
            'incoming_cross_department_courses' => $incoming,
            'courses' => $courses
                ->map(fn (Course $course): array => $this->present($course, $instructorClasses[(int) $course->id] ?? 0)
                    + ['section_assignments' => $sectionAssignments[(int) $course->id] ?? []])
                ->sortBy([['year_level', 'asc'], ['course_code', 'asc']])
                ->values(),
        ]);
    }

    /**
     * @return Collection<int, int|string>
     */
    private function activeCurriculumIds(int $departmentId, ?int $programId = null): Collection
    {
        return Curriculum::query()
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->when($programId !== null, fn ($query) => $query->where(function ($scope) use ($programId): void {
                $scope->whereNull('program_id')->orWhere('program_id', $programId);
            }))
            ->pluck('id');
    }

    private function activeSemester(): ?Semester
    {
        return Cache::remember(
            ApiCache::key('semesters.active'),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => Semester::query()->where('is_active', true)->first(),
        );
    }

    private static function pivotSemester(?string $semesterPeriod): ?int
    {
        return match ($semesterPeriod) {
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
            default => null,
        };
    }

    /**
     * @return Collection<int, int>
     */
    private function coursesPlacedInSemester(int $semester): Collection
    {
        return DB::table('curriculum_course')
            ->join('curriculum', 'curriculum.id', '=', 'curriculum_course.curriculum_id')
            ->where('curriculum.status', 'active')
            ->where('curriculum_course.semester', $semester)
            ->distinct()
            ->pluck('curriculum_course.course_id')
            ->map(static fn ($id): int => (int) $id);
    }

    /**
     * @param  Collection<int, int|string>  $curriculumIds
     * @return Collection<int, Course>
     */
    private function departmentCourses(int $departmentId, Collection $curriculumIds, ?int $activePeriod): Collection
    {
        if ($curriculumIds->isEmpty()) {
            return new Collection;
        }

        $placements = $this->curriculumPlacements($curriculumIds, $activePeriod);

        if ($placements->isEmpty()) {
            return new Collection;
        }

        $courses = Course::query()
            ->with(['department', 'teachingDepartment', 'teachingProgram', 'program'])
            ->where('status', 'active')
            ->whereIn('courses.id', $placements->keys())
            ->where(function ($owner) use ($departmentId): void {
                $owner->whereNull('department_id')
                    ->orWhere('department_id', $departmentId);
            })
            ->orderBy('course_code')
            ->get();

        return $this->applyCurriculumYearLevels($courses, $placements);
    }

    /**
     * @param  Collection<int, int|string>  $curriculumIds
     * @return Collection<int|string, object> keyed by course_id
     */
    private function curriculumPlacements(Collection $curriculumIds, ?int $activePeriod): Collection
    {
        return DB::table('curriculum_course')
            ->join('curriculum', 'curriculum.id', '=', 'curriculum_course.curriculum_id')
            ->leftJoin('programs', 'programs.id', '=', 'curriculum.program_id')
            ->whereIn('curriculum_course.curriculum_id', $curriculumIds)
            ->when($activePeriod !== null, fn ($query) => $query->where('curriculum_course.semester', $activePeriod))
            ->orderByDesc('curriculum.effective_school_year')
            ->orderByDesc('curriculum_course.curriculum_id')
            ->get(['curriculum_course.course_id', 'curriculum_course.year_level', 'curriculum_course.curriculum_id', 'curriculum.name as curriculum_name', 'curriculum.program_id as curriculum_program_id', 'programs.code as curriculum_program_code', 'programs.name as curriculum_program_name', 'programs.major as curriculum_program_major'])
            ->unique('course_id')
            ->keyBy('course_id');
    }

    /**
     * @param  Collection<int, Course>  $courses
     * @param  Collection<int|string, object>  $placements
     * @return Collection<int, Course>
     */
    private function applyCurriculumYearLevels(Collection $courses, Collection $placements): Collection
    {
        if ($placements->isEmpty() || $courses->isEmpty()) {
            return $courses;
        }

        return $courses->each(function (Course $course) use ($placements): void {
            $placement = $placements->get($course->id);

            if ($placement !== null) {
                $course->year_level = (string) $placement->year_level;
                $course->curriculum_program_id = $placement->curriculum_program_id;
                $course->curriculum_program_code = $placement->curriculum_program_code;
                $course->curriculum_program_name = $placement->curriculum_program_name;
                $course->curriculum_program_major = $placement->curriculum_program_major;
            }
        });
    }

    /**
     * A Program Head may not manage a course that belongs only to a sibling program of
     * their department. Course Assignment opens per program, so without this an approved
     * program could change a course whose own program is still awaiting VPAA approval.
     * Courses shared across the department, and other colleges' courses (decision 3 in
     * CourseTeachingAssignmentTest), are unaffected.
     *
     * @param  list<int>  $courseIds
     */
    private function refuseOutsideProgram(?User $actor, array $courseIds): ?JsonResponse
    {
        if ($actor?->role !== 'program_head' || $actor->program_id === null || $actor->department_id === null || $courseIds === []) {
            return null;
        }

        $outside = $this->siblingProgramCourseIds((int) $actor->department_id, (int) $actor->program_id, $courseIds);
        if ($outside === []) {
            return null;
        }

        return response()->json([
            'message' => self::OUTSIDE_PROGRAM_MESSAGE,
            'outside_program_course_ids' => $outside,
        ], 403);
    }

    /**
     * Of the given courses, those that belong to a sibling program of the department —
     * placed in, or owned by, another of its programs — and not to `$programId`'s own
     * active curricula (including the department's shared ones) or to the program itself.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    private function siblingProgramCourseIds(int $departmentId, int $programId, array $courseIds): array
    {
        $placedIn = fn (Collection $curriculumIds): Collection => $curriculumIds->isEmpty()
            ? collect()
            : DB::table('curriculum_course')
                ->whereIn('curriculum_id', $curriculumIds)
                ->whereIn('course_id', $courseIds)
                ->pluck('course_id')
                ->map(static fn ($id): int => (int) $id);

        $siblingProgramIds = Program::query()
            ->where('department_id', $departmentId)
            ->whereKeyNot($programId)
            ->pluck('id');
        if ($siblingProgramIds->isEmpty()) {
            return [];
        }

        $sibling = $placedIn(Curriculum::query()
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->whereIn('program_id', $siblingProgramIds)
            ->pluck('id'))
            ->merge(Course::query()->whereIn('id', $courseIds)->whereIn('program_id', $siblingProgramIds)->pluck('id')
                ->map(static fn ($id): int => (int) $id));

        $own = $placedIn($this->activeCurriculumIds($departmentId, $programId))
            ->merge(Course::query()->whereIn('id', $courseIds)->where('program_id', $programId)->pluck('id')
                ->map(static fn ($id): int => (int) $id));

        return $sibling->unique()->diff($own)->sort()->values()->all();
    }

    /**
     * Which targets a Program Head or department office may not give their own
     * department's courses to — the single source for both the writes and the listing.
     * Own programs are resolved per request from program ownership: the Program Head's
     * program, or for the office every program without an active Program Head.
     *
     * The own department is excluded outright when it has a single program, when every
     * one of its programs is the user's own, or when a Program Head has no program to
     * identify (fail closed). Otherwise a specific program other than the user's own is
     * required, since "any program" of the department would include it.
     *
     * @return array{department_id: int, own_program_ids: list<int>, own_department_excluded: bool, requires_program: bool}|null
     */
    private function targetRestrictions(?User $actor): ?array
    {
        if ($actor === null || $actor->department_id === null || ! in_array($actor->role, self::RESTRICTED_ROLES, true)) {
            return null;
        }

        $departmentId = (int) $actor->department_id;
        $programIds = Program::query()->where('department_id', $departmentId)->pluck('id')->map('intval')->all();
        $own = $this->authorization->writableProgramIdsFor($actor);
        if ($actor->role === 'program_head' && $actor->program_id !== null) {
            $own[] = (int) $actor->program_id;
        }
        $own = array_values(array_unique(array_intersect($own, $programIds)));
        sort($own);

        $excluded = count($programIds) <= 1
            || array_diff($programIds, $own) === []
            || ($actor->role === 'program_head' && $own === []);

        return [
            'department_id' => $departmentId,
            'own_program_ids' => $own,
            'own_department_excluded' => $excluded,
            'requires_program' => ! $excluded,
        ];
    }

    /**
     * Refuses giving any of the actor's own department's courses to the actor's own
     * department or program (see targetRestrictions). Another college's course, which
     * any college may claim (decision 3 in CourseTeachingAssignmentTest), is unaffected.
     *
     * @param  list<int>  $courseIds
     */
    private function ownTargetRefusal(?User $actor, array $courseIds, ?int $targetDepartmentId, ?int $targetProgramId): ?JsonResponse
    {
        $restrictions = $this->targetRestrictions($actor);
        if ($restrictions === null || $targetDepartmentId !== $restrictions['department_id']
            || ! $this->includesOwnDepartmentCourse($restrictions['department_id'], $courseIds)) {
            return null;
        }

        [$reason, $message] = match (true) {
            $restrictions['own_department_excluded'] => ['own_department', self::OWN_DEPARTMENT_MESSAGE],
            $targetProgramId === null => ['own_department_program_required', self::OWN_DEPARTMENT_PROGRAM_REQUIRED_MESSAGE],
            in_array($targetProgramId, $restrictions['own_program_ids'], true) => ['own_program', self::OWN_PROGRAM_MESSAGE],
            default => [null, null],
        };

        return $reason === null ? null : response()->json(['message' => $message, 'reason' => $reason], 422);
    }

    /**
     * Whether any course belongs to the department: owned by it, or unowned and placed
     * in one of its active curricula (what the listing offers as the department's).
     *
     * @param  list<int>  $courseIds
     */
    private function includesOwnDepartmentCourse(int $departmentId, array $courseIds): bool
    {
        if ($courseIds === []) {
            return false;
        }

        if (Course::query()->whereIn('id', $courseIds)->where('department_id', $departmentId)->exists()) {
            return true;
        }

        $curriculumIds = $this->activeCurriculumIds($departmentId);

        return $curriculumIds->isNotEmpty() && DB::table('curriculum_course')
            ->join('courses', 'courses.id', '=', 'curriculum_course.course_id')
            ->whereIn('curriculum_course.curriculum_id', $curriculumIds)
            ->whereIn('curriculum_course.course_id', $courseIds)
            ->whereNull('courses.department_id')
            ->exists();
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'teaching_department_id' => 'present|nullable|integer|exists:departments,id,deleted_at,NULL',
            'teaching_program_id' => 'sometimes|nullable|integer|exists:programs,id,deleted_at,NULL',
        ]);

        if ($locked = $this->approvalGate->refusal($request->user())) {
            return $locked;
        }

        if ($outside = $this->refuseOutsideProgram($request->user(), [(int) $course->id])) {
            return $outside;
        }

        $teachingDepartmentId = $validated['teaching_department_id'] === null
            ? null
            : (int) $validated['teaching_department_id'];

        $teachingProgramId = empty($validated['teaching_program_id']) ? null : (int) $validated['teaching_program_id'];
        $teachingProgram = $teachingProgramId === null ? null : Program::findOrFail($teachingProgramId);
        if ($teachingProgram !== null) {
            $teachingDepartmentId = (int) $teachingProgram->department_id;
        }

        if ($teachingDepartmentId !== null && ($refusal = SchedulingPolicy::majorDelegationRefusal($course, $teachingProgram, $teachingDepartmentId))) {
            return response()->json(['message' => $refusal], 422);
        }

        if ($own = $this->ownTargetRefusal($request->user(), [(int) $course->id], $teachingDepartmentId, $teachingProgramId)) {
            return $own;
        }

        if ($locked = $this->refuseIfInstructorAssigned($course, $teachingDepartmentId)) {
            return $locked;
        }

        $this->store($course, $teachingDepartmentId, $teachingProgramId, $request->user());

        return response()->json([
            'message' => $teachingDepartmentId === null
                ? 'Teaching college cleared.'
                : 'Teaching college saved.',
            'course' => $this->present($course, $this->classesWithInstructor([(int) $course->id])[(int) $course->id] ?? 0),
        ]);
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['integer', 'distinct', 'exists:courses,id,deleted_at,NULL'],
            'teaching_department_id' => ['required', 'integer', 'exists:departments,id,deleted_at,NULL'],
            'teaching_program_id' => ['nullable', 'integer', 'exists:programs,id,deleted_at,NULL'],
        ]);
        if ($locked = $this->approvalGate->refusal($request->user())) {
            return $locked;
        }
        if ($outside = $this->refuseOutsideProgram($request->user(), array_map('intval', $validated['course_ids']))) {
            return $outside;
        }
        $targetId = (int) $validated['teaching_department_id'];
        $targetProgramId = isset($validated['teaching_program_id']) ? (int) $validated['teaching_program_id'] : null;
        $targetProgram = $targetProgramId === null ? null : Program::findOrFail($targetProgramId);
        if ($targetProgram !== null) {
            $targetId = (int) $targetProgram->department_id;
        }
        $courses = Course::query()->with('program')->whereIn('id', $validated['course_ids'])->get();
        foreach ($courses as $course) {
            if ($refusal = SchedulingPolicy::majorDelegationRefusal($course, $targetProgram, $targetId)) {
                return response()->json(['message' => "{$course->course_code}: {$refusal}"], 422);
            }
        }
        if ($own = $this->ownTargetRefusal($request->user(), $courses->pluck('id')->map('intval')->all(), $targetId, $targetProgramId)) {
            return $own;
        }
        $instructorClasses = $this->classesWithInstructor($courses->pluck('id')->map('intval')->all());
        $locked = $courses->filter(fn (Course $course): bool => ($instructorClasses[(int) $course->id] ?? 0) > 0
            && $this->effectiveTeachingDepartmentId($course, $targetId) !== $this->effectiveTeachingDepartmentId($course, $this->storedTeachingDepartmentId($course)));
        if ($locked->isNotEmpty()) {
            $codes = $locked->pluck('course_code')->sort()->values()->implode(', ');

            return response()->json([
                'message' => "{$codes} already ".($locked->count() === 1 ? 'has' : 'have').' an instructor assigned this semester, so another department cannot be assigned to teach '.($locked->count() === 1 ? 'it' : 'them').'. Remove those instructor assignments first.',
                'locked_course_ids' => $locked->pluck('id')->map('intval')->values()->all(),
            ], 422);
        }
        $actor = $request->user();
        $sourceProgramIds = $this->sourceProgramIds($courses, $actor);
        DB::transaction(fn () => $courses->each(function (Course $course) use ($targetId, $targetProgramId, $sourceProgramIds): void {
            $course->update([
                'teaching_department_id' => $targetId,
                'teaching_program_id' => $targetProgramId,
                'teaching_source_program_id' => $sourceProgramIds[(int) $course->id] ?? null,
            ]);
        }));
        ApiCache::forgetGroups(['instructor_assignments.index', 'courses.index', 'initial.data']);
        $courses->load(['teachingSourceProgram', 'department']);
        $source = $this->sourceLabel($courses, $actor);
        $target = $targetProgram?->shortLabel() ?? Departments::find($targetId)?->department_name ?? 'your department';
        $changedCourses = $courses->filter(fn (Course $course): bool => $course->wasChanged(['teaching_department_id', 'teaching_program_id', 'teaching_source_program_id']));
        $count = $changedCourses->count();
        if ($count > 0) {
            $this->notifications->notifyRoles(
                ['secretary', 'program_head'],
                'incoming_cross_department_courses',
                'Cross-department courses assigned',
                "{$source} assigned {$count} course".($count === 1 ? '' : 's')." to {$target}. View Cross-Department.",
                $actor,
                $targetId,
                null,
                null,
                ['course_ids' => $changedCourses->pluck('id')->values()->all(), 'source_department_id' => $actor?->department_id, 'teaching_department_id' => $targetId, 'teaching_program_id' => $targetProgramId],
                $targetProgramId === null ? null : [$targetProgramId],
            );
        }

        return response()->json(['course_ids' => $courses->pluck('id')->values()->all()]);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        if ($locked = $this->approvalGate->refusal($request->user())) {
            return $locked;
        }

        if ($outside = $this->refuseOutsideProgram($request->user(), [(int) $course->id])) {
            return $outside;
        }

        if ($locked = $this->refuseIfInstructorAssigned($course, null)) {
            return $locked;
        }

        $this->store($course, null, null, $request->user());

        return response()->json([
            'message' => 'Teaching college removed.',
            'course' => $this->present($course, 0),
        ]);
    }

    public function batchDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['integer', 'distinct', 'exists:courses,id,deleted_at,NULL'],
        ]);
        if ($locked = $this->approvalGate->refusal($request->user())) {
            return $locked;
        }
        if ($outside = $this->refuseOutsideProgram($request->user(), array_map('intval', $validated['course_ids']))) {
            return $outside;
        }

        $courses = Course::query()
            ->whereIn('id', $validated['course_ids'])
            ->whereNotNull('teaching_department_id')
            ->get();
        $instructorClasses = $this->classesWithInstructor($courses->pluck('id')->map('intval')->all());
        [$locked, $removable] = $courses->partition(
            fn (Course $course): bool => ($instructorClasses[(int) $course->id] ?? 0) > 0
                && $this->effectiveTeachingDepartmentId($course, null) !== $this->effectiveTeachingDepartmentId($course, $this->storedTeachingDepartmentId($course))
        );

        DB::transaction(fn () => $removable->each(fn (Course $course) => $course->update([
            'teaching_department_id' => null,
            'teaching_program_id' => null,
            'teaching_source_program_id' => null,
        ])));
        ApiCache::forgetGroups(['instructor_assignments.index', 'courses.index', 'initial.data']);

        $count = $removable->count();

        return response()->json([
            'message' => "{$count} teaching assignment".($count === 1 ? '' : 's').' removed.',
            'course_ids' => $removable->pluck('id')->map('intval')->values()->all(),
            'locked_course_ids' => $locked->pluck('id')->map('intval')->values()->all(),
        ]);
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<int, int>
     */
    private function classesWithInstructor(array $courseIds): array
    {
        $semester = $this->activeSemester();
        if ($semester === null || $courseIds === []) {
            return [];
        }

        return Schedule::query()
            ->where('semester_id', $semester->id)
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('faculty_id')
            ->selectRaw('course_id, COUNT(DISTINCT section_id) AS classes')
            ->groupBy('course_id')
            ->pluck('classes', 'course_id')
            ->mapWithKeys(static fn ($classes, $courseId): array => [(int) $courseId => (int) $classes])
            ->all();
    }

    private function storedTeachingDepartmentId(Course $course): ?int
    {
        return $course->teaching_department_id === null ? null : (int) $course->teaching_department_id;
    }

    private function effectiveTeachingDepartmentId(Course $course, ?int $override): ?int
    {
        return $override ?? ($course->department_id === null ? null : (int) $course->department_id);
    }

    private function refuseIfInstructorAssigned(Course $course, ?int $teachingDepartmentId): ?JsonResponse
    {
        $unchanged = $this->effectiveTeachingDepartmentId($course, $teachingDepartmentId)
            === $this->effectiveTeachingDepartmentId($course, $this->storedTeachingDepartmentId($course));
        if ($unchanged) {
            return null;
        }

        $classes = $this->classesWithInstructor([(int) $course->id])[(int) $course->id] ?? 0;
        if ($classes === 0) {
            return null;
        }

        return response()->json([
            'message' => "{$course->course_code} already has an instructor assigned in {$classes} ".($classes === 1 ? 'class' : 'classes').' this semester, so another department cannot be assigned to teach it. Remove those instructor assignments first.',
            'instructor_assigned_classes' => $classes,
        ], 422);
    }

    private function store(Course $course, ?int $teachingDepartmentId, ?int $teachingProgramId, ?User $actor = null): void
    {
        $course->teaching_department_id = $teachingDepartmentId;
        $course->teaching_program_id = $teachingProgramId;
        $course->teaching_source_program_id = $teachingDepartmentId === null
            ? null
            : ($this->sourceProgramIds(collect([$course]), $actor)[(int) $course->id] ?? null);
        $course->save();

        if ($teachingDepartmentId !== null && $course->wasChanged(['teaching_department_id', 'teaching_program_id', 'teaching_source_program_id'])) {
            $course->load(['department', 'teachingDepartment', 'teachingProgram', 'teachingSourceProgram']);
            $source = $this->sourceLabel(collect([$course]), $actor);
            $target = $course->teachingProgram?->shortLabel() ?? $course->teachingDepartment?->department_name;
            $this->notifications->notifyRoles(
                ['secretary', 'program_head'],
                'incoming_cross_department_course',
                'Cross-department course assigned',
                "{$source} assigned {$course->course_code} to {$target}.",
                $actor,
                $teachingDepartmentId,
                null,
                null,
                [
                    'course_id' => $course->id,
                    'course_code' => $course->course_code,
                    'source_department_id' => $course->department_id,
                    'teaching_department_id' => $teachingDepartmentId,
                ],
                $teachingProgramId === null ? null : [$teachingProgramId],
            );
        }

        ApiCache::forgetGroups(['instructor_assignments.index', 'courses.index', 'initial.data']);

        $course->load(['department', 'teachingDepartment', 'teachingProgram', 'program']);
    }

    /**
     * @param  Collection<int, Course>  $courses
     * @return array<int, int|null> keyed by course id
     */
    private function sourceProgramIds(Collection $courses, ?User $actor): array
    {
        if ($actor?->role === 'program_head' && $actor->program_id !== null) {
            return $courses->mapWithKeys(fn (Course $course): array => [(int) $course->id => (int) $actor->program_id])->all();
        }

        $placements = null;
        if ($actor?->department_id) {
            $curriculumIds = $this->activeCurriculumIds((int) $actor->department_id);
            $placements = $curriculumIds->isEmpty() ? null : $this->curriculumPlacements($curriculumIds, null);
        }

        return $courses->mapWithKeys(function (Course $course) use ($placements): array {
            $programId = $course->program_id ?? $placements?->get($course->id)?->curriculum_program_id;

            return [(int) $course->id => $programId === null ? null : (int) $programId];
        })->all();
    }

    /**
     * This department's sections taking each delegated course this semester, with the instructor the
     * teaching college assigned to each (a section counts as assigned only when every meeting has one).
     *
     * @param  list<int>  $courseIds
     * @return array<int, list<array{section_id: int, section_name: string, instructor_name: string|null, status: string}>>
     */
    private function sectionAssignments(array $courseIds, int $departmentId, ?int $programId): array
    {
        $semester = $this->activeSemester();
        if ($semester === null || $courseIds === []) {
            return [];
        }

        return Schedule::query()
            ->with(['section:id,section_name', 'faculty:id,first_name,last_name'])
            ->where('semester_id', $semester->id)
            ->where('department_id', $departmentId)
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->whereIn('course_id', $courseIds)
            ->get(['id', 'course_id', 'section_id', 'faculty_id'])
            ->groupBy('course_id')
            ->map(fn (Collection $schedules): array => $schedules->groupBy('section_id')
                ->map(function (Collection $meetings): array {
                    $instructors = $meetings->pluck('faculty')->filter()->unique('id')
                        ->map(fn ($faculty): string => trim("{$faculty->first_name} {$faculty->last_name}"))
                        ->filter()->values();
                    $unassigned = $meetings->contains(fn (Schedule $meeting): bool => $meeting->faculty_id === null);

                    return [
                        'section_id' => (int) $meetings->first()->section_id,
                        'section_name' => $meetings->first()->section?->section_name ?? 'Section',
                        'instructor_name' => $instructors->isEmpty() ? null : $instructors->implode(' / '),
                        'status' => $unassigned
                            ? ($instructors->isEmpty() ? 'Awaiting instructor' : 'Partially assigned')
                            : 'Assigned',
                    ];
                })
                ->sortBy('section_name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all())
            ->mapWithKeys(static fn (array $sections, $courseId): array => [(int) $courseId => $sections])
            ->all();
    }

    /**
     * @param  Collection<int, Course>  $courses
     */
    private function sourceLabel(Collection $courses, ?User $actor): string
    {
        $labels = $courses->map(fn (Course $course) => $course->teachingSourceProgram?->shortLabel())->filter()->unique();

        return $labels->isNotEmpty()
            ? $labels->sort()->implode(', ')
            : ($actor?->department?->department_name ?? 'A department');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Course $course, int $instructorAssignedClasses = 0): array
    {
        return [
            'id' => (int) $course->id,
            'course_code' => $course->course_code,
            'course_name' => $course->course_name,
            'year_level' => $course->year_level === null ? null : (int) $course->year_level,
            'course_category' => $course->course_category,
            'units' => $course->units,
            'department_id' => $course->department_id === null ? null : (int) $course->department_id,
            'department_code' => $course->department?->department_code,
            'department_name' => $course->department?->department_name,
            'teaching_department_id' => $course->teaching_department_id === null
                ? null
                : (int) $course->teaching_department_id,
            'teaching_department_code' => $course->teachingDepartment?->department_code,
            'teaching_department_name' => $course->teachingDepartment?->department_name,
            'teaching_program_id' => $course->teaching_program_id === null ? null : (int) $course->teaching_program_id,
            'teaching_program_code' => $course->teachingProgram?->code,
            'teaching_program_name' => $course->teachingProgram?->name,
            'program_id' => $course->program_id === null ? null : (int) $course->program_id,
            'program_code' => $course->program?->code,
            'program_name' => $course->program?->name,
            'program_major' => $course->program?->major,
            'curriculum_program_id' => $course->getAttribute('curriculum_program_id') === null
                ? null
                : (int) $course->getAttribute('curriculum_program_id'),
            'curriculum_program_code' => $course->getAttribute('curriculum_program_code'),
            'curriculum_program_name' => $course->getAttribute('curriculum_program_name'),
            'curriculum_program_major' => $course->getAttribute('curriculum_program_major'),
            'delegable' => SchedulingPolicy::isDelegableCourse($course),
            'is_major' => SchedulingPolicy::isMajorCourse($course),
            'instructor_assigned_classes' => $instructorAssignedClasses,
            'effective_teaching_department_id' => SchedulingPolicy::assignedTeachingDepartmentId($course),
        ];
    }

    private function presentIncoming(Course $course): array
    {
        $scheduleQuery = $course->schedules()->whereHas('academicSemester', fn ($query) => $query->where('is_active', true));
        $scheduleCount = (clone $scheduleQuery)->count();
        $unassignedCount = (clone $scheduleQuery)->whereNull('faculty_id')->count();

        return [
            'id' => (int) $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name,
            'source_department_id' => $course->department_id === null ? null : (int) $course->department_id,
            'source_department_code' => $course->department?->department_code, 'source_department_name' => $course->department?->department_name,
            'source_program_id' => $course->teaching_source_program_id === null ? null : (int) $course->teaching_source_program_id,
            'source_program_label' => $course->teachingSourceProgram?->shortLabel(),
            'teaching_department_id' => (int) $course->teaching_department_id, 'year_level' => $course->year_level === null ? null : (int) $course->year_level,
            'units' => $course->units, 'assignment_status' => $scheduleCount === 0 ? 'Awaiting schedule' : ($unassignedCount > 0 ? 'Instructor assignment pending' : 'Ready for teaching'),
            'schedule_count' => $scheduleCount,
        ];
    }
}
