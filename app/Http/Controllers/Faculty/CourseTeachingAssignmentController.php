<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScheduleHistoryRecorder;
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

    public const OFFICE_LOCKED_MESSAGE = 'Every program in your department is managed by its Program Head, so Course Assignment is handled by the Program Heads.';

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

        if ($locked = $this->officeLockedOut($request)) {
            return $locked;
        }

        $programId = $request->user()?->role === 'program_head'
            ? ($request->user()?->program_id === null ? null : (int) $request->user()->program_id)
            : null;
        // The department office does not see Program Heads' programs here: their curricula,
        // sections and incoming hand-offs are left out, and a shared course one of them uses is
        // listed as `program_head_managed` (its course-wide assignment is locked).
        $headManagedProgramIds = $this->authorization->headManagedProgramIds($request);
        $activeSemester = $this->activeSemester();
        $activePeriod = self::pivotSemester($activeSemester?->semester);

        $curriculumIds = $this->activeCurriculumIds($departmentId, $programId)
            ->when($headManagedProgramIds !== [], fn (Collection $ids): Collection => Curriculum::query()
                ->whereIn('id', $ids)
                ->where(fn ($scope) => $scope->whereNull('program_id')->orWhereNotIn('program_id', $headManagedProgramIds))
                ->pluck('id'));
        $courses = $this->departmentCourses($departmentId, $curriculumIds, $activePeriod);
        $headManagedCourseIds = $this->authorization->headManagedCourseIds($request, $courses->pluck('id')->map('intval')->all());
        $instructorClasses = $this->classesWithInstructor($courses->pluck('id')->map('intval')->all());
        $sectionAssignments = $this->sectionAssignments(
            $courses->pluck('id')->map('intval')->values()->all(),
            $departmentId,
            $programId,
            $headManagedProgramIds,
        );
        // Incoming: courses delegated here as a whole, plus courses with returned sections handed
        // here one by one.
        $sectionLevelCourseIds = $activeSemester === null ? collect() : Schedule::query()
            ->where('semester_id', $activeSemester->id)
            ->whereNotNull('delegation_returned_from_department_id')
            ->where('section_teaching_department_id', $departmentId)
            ->when($programId !== null, fn ($query) => $query->where('section_teaching_program_id', $programId))
            ->when($headManagedProgramIds !== [], fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('section_teaching_program_id')
                ->orWhereNotIn('section_teaching_program_id', $headManagedProgramIds)))
            ->distinct()->pluck('course_id')->map('intval');
        $incoming = Course::query()->with(['department', 'teachingDepartment', 'teachingProgram', 'teachingSourceProgram', 'program'])
            ->where('status', 'active')
            ->where(fn ($handed) => $handed->delegatedTo($departmentId, $programId)->orWhereIn('courses.id', $sectionLevelCourseIds))
            ->when($headManagedProgramIds !== [], fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('teaching_program_id')
                ->orWhereNotIn('teaching_program_id', $headManagedProgramIds)))
            ->when($activePeriod !== null, fn ($query) => $query->whereIn(
                'courses.id',
                $this->coursesPlacedInSemester($activePeriod),
            ))
            ->orderBy('course_code')->get()->map(fn (Course $course): array => $this->presentIncoming($course, $departmentId))->values();

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
                    + ['section_assignments' => $sectionAssignments[(int) $course->id] ?? []]
                    + ['program_head_managed' => in_array((int) $course->id, $headManagedCourseIds, true)])
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
        $locked = request()->attributes->get('course_assignment_semester');
        if ($locked instanceof Semester) {
            return $locked;
        }

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
     * The department office manages Course Assignment only for programs without a Program Head.
     * When every program of its department has one, the whole module is the Program Heads'.
     */
    private function officeLockedOut(Request $request): ?JsonResponse
    {
        return $this->authorization->headManagedProgramIds($request) !== []
            && $this->authorization->writableProgramIds($request) === []
                ? response()->json(['message' => self::OFFICE_LOCKED_MESSAGE, 'reason' => 'program_head_managed'], 403)
                : null;
    }

    /**
     * The department office may not change the course-wide teaching assignment of a course that
     * a Program Head's program uses: it would move that program's classes too.
     *
     * @param  list<int>  $courseIds
     */
    private function refuseHeadManagedCourses(Request $request, array $courseIds): ?JsonResponse
    {
        if ($locked = $this->officeLockedOut($request)) {
            return $locked;
        }

        $managed = $this->authorization->headManagedCourseIds($request, $courseIds);

        return $managed === [] ? null : response()->json([
            'message' => ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_COURSE_MESSAGE,
            'program_head_managed_course_ids' => $managed,
        ], 403);
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
        if ($headManaged = $this->refuseHeadManagedCourses($request, [(int) $course->id])) {
            return $headManaged;
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
        if ($headManaged = $this->refuseHeadManagedCourses($request, array_map('intval', $validated['course_ids']))) {
            return $headManaged;
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
                $this->activeSemester()?->id,
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
        if ($headManaged = $this->refuseHeadManagedCourses($request, [(int) $course->id])) {
            return $headManaged;
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
        if ($headManaged = $this->refuseHeadManagedCourses($request, array_map('intval', $validated['course_ids']))) {
            return $headManaged;
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
     * Hands returned sections, one by one, to another department or program. Only a section a
     * receiving department returned (detached from the course-level assignment) qualifies, and
     * only while nobody teaches it. The course-level assignment, the course's other sections and
     * their instructors are untouched. A section has a single teaching column, so it can never
     * be with two departments at once.
     */
    public function assignSections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_ids' => ['required', 'array', 'min:1', 'max:500'],
            'schedule_ids.*' => ['integer', 'distinct', 'exists:schedules,id'],
            'teaching_department_id' => ['required', 'integer', 'exists:departments,id,deleted_at,NULL'],
            'teaching_program_id' => ['nullable', 'integer', 'exists:programs,id,deleted_at,NULL'],
        ]);
        $actor = $request->user();
        if ($locked = $this->approvalGate->refusal($actor)) {
            return $locked;
        }

        $targetProgramId = isset($validated['teaching_program_id']) ? (int) $validated['teaching_program_id'] : null;
        $targetProgram = $targetProgramId === null ? null : Program::findOrFail($targetProgramId);
        $targetId = $targetProgram !== null ? (int) $targetProgram->department_id : (int) $validated['teaching_department_id'];

        [$classes, $refusal] = $this->sectionClasses($request, array_map('intval', $validated['schedule_ids']));
        if ($refusal !== null) {
            return $refusal;
        }

        $courses = $classes->map(fn (Collection $rows) => $rows->first()->course)->filter()->unique('id')->values();
        foreach ($courses as $course) {
            if ($refusal = SchedulingPolicy::majorDelegationRefusal($course, $targetProgram, $targetId)) {
                return response()->json(['message' => "{$course->course_code}: {$refusal}"], 422);
            }
        }
        if ($own = $this->ownTargetRefusal($actor, $courses->pluck('id')->map('intval')->all(), $targetId, $targetProgramId)) {
            return $own;
        }

        $scheduleIds = $this->writeSectionTeaching($classes, $targetId, $targetProgramId, $actor, 'cross_department_section_assigned');
        if ($scheduleIds instanceof JsonResponse) {
            return $scheduleIds;
        }

        $target = $targetProgram?->shortLabel() ?? Departments::find($targetId)?->department_name ?? 'the department';
        $labels = $this->classLabels($classes);
        $this->notifications->notifyRoles(
            ['secretary', 'program_head'],
            'incoming_cross_department_courses',
            $classes->count() === 1 ? 'Cross-department section assigned' : 'Cross-department sections assigned',
            ($actor?->department?->department_name ?? 'A department')." assigned {$labels} to {$target}. View Cross-Department.",
            $actor,
            $targetId,
            $this->activeSemester()?->id,
            null,
            [
                'schedule_ids' => $scheduleIds,
                'course_ids' => $courses->pluck('id')->map('intval')->values()->all(),
                'source_department_id' => $actor?->department_id,
                'teaching_department_id' => $targetId,
                'teaching_program_id' => $targetProgramId,
                'program_ids' => $targetProgramId === null ? null : [$targetProgramId],
            ],
            $targetProgramId === null ? null : [$targetProgramId],
        );

        return response()->json([
            'message' => ($classes->count() === 1 ? '1 section' : "{$classes->count()} sections")." assigned to {$target}.",
            'schedule_ids' => $scheduleIds,
            'sections_assigned' => $classes->count(),
        ]);
    }

    /** Takes returned sections back from a section-level assignment: the offering department teaches them. */
    public function removeSections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_ids' => ['required', 'array', 'min:1', 'max:500'],
            'schedule_ids.*' => ['integer', 'distinct', 'exists:schedules,id'],
        ]);
        if ($locked = $this->approvalGate->refusal($request->user())) {
            return $locked;
        }

        [$classes, $refusal] = $this->sectionClasses($request, array_map('intval', $validated['schedule_ids']));
        if ($refusal !== null) {
            return $refusal;
        }

        $scheduleIds = $this->writeSectionTeaching($classes, null, null, $request->user(), 'cross_department_section_removed');
        if ($scheduleIds instanceof JsonResponse) {
            return $scheduleIds;
        }

        return response()->json([
            'message' => ($classes->count() === 1 ? '1 section is' : "{$classes->count()} sections are").' back with your department.',
            'schedule_ids' => $scheduleIds,
            'sections_removed' => $classes->count(),
        ]);
    }

    /**
     * The whole classes (every meeting of each section's course) behind the given ids, or a
     * refusal. They must be the caller's own department's (and a Program Head's own program's)
     * classes in the active semester, returned by a receiving department, and free to move.
     *
     * @param  list<int>  $scheduleIds
     * @return array{0: Collection<string, Collection<int, Schedule>>, 1: JsonResponse|null}
     */
    private function sectionClasses(Request $request, array $scheduleIds): array
    {
        $actor = $request->user();
        $departmentId = (int) ($actor?->department_id ?? 0);
        $semester = $this->activeSemester();
        $seeds = Schedule::query()->with('section:id,program_id')->whereIn('id', $scheduleIds)->get();
        if ($semester === null || $seeds->contains(fn (Schedule $seed): bool => (int) $seed->semester_id !== (int) $semester->id)) {
            return [collect(), response()->json(['message' => 'Sections can only be reassigned for the active semester.'], 422)];
        }

        $programId = $actor?->role === 'program_head' ? (int) ($actor->program_id ?? 0) : null;
        if ($departmentId === 0 || $seeds->contains(fn (Schedule $seed): bool => (int) $seed->department_id !== $departmentId
            || ($programId !== null && (int) ($seed->program_id ?? $seed->section?->program_id ?? 0) !== $programId))) {
            return [collect(), response()->json(['message' => $programId !== null
                ? self::OUTSIDE_PROGRAM_MESSAGE
                : 'You can only reassign your own department\'s sections.'], 403)];
        }
        if ($outside = $this->refuseOutsideProgram($actor, $seeds->pluck('course_id')->map('intval')->unique()->values()->all())) {
            return [collect(), $outside];
        }
        if ($locked = $this->officeLockedOut($request)) {
            return [collect(), $locked];
        }
        $headManagedProgramIds = $this->authorization->headManagedProgramIds($request);
        if ($headManagedProgramIds !== [] && $seeds->contains(fn (Schedule $seed): bool => in_array(
            (int) ($seed->program_id ?? $seed->section?->program_id ?? 0),
            $headManagedProgramIds,
            true,
        ))) {
            return [collect(), response()->json(['message' => ScheduleAuthorizationService::PROGRAM_HEAD_MANAGED_MESSAGE], 403)];
        }

        $classes = $this->classRows($seeds, (int) $semester->id);
        $blocked = $classes->map(function (Collection $rows): ?array {
            $reason = $this->classBlockReason($rows);
            $first = $rows->first();

            return $reason === null ? null : [
                'section_id' => (int) $first->section_id,
                'section_name' => $first->section?->section_name,
                'course_id' => (int) $first->course_id,
                'course_code' => $first->course?->course_code,
                'reason' => $reason,
            ];
        })->filter()->values();
        if ($blocked->isNotEmpty()) {
            return [collect(), response()->json([
                'message' => $blocked->count() === 1
                    ? "{$blocked[0]['course_code']} ({$blocked[0]['section_name']}): {$blocked[0]['reason']}"
                    : 'Some selected sections cannot be reassigned. Only returned sections without an instructor can be.',
                'blocked' => $blocked->all(),
            ], 422)];
        }

        return [$classes, null];
    }

    /**
     * @param  Collection<int, Schedule>  $seeds
     * @return Collection<string, Collection<int, Schedule>>
     */
    private function classRows(Collection $seeds, int $semesterId, bool $lock = false): Collection
    {
        return Schedule::query()
            ->with(['course', 'section:id,section_name,program_id', 'faculty:id'])
            ->where('semester_id', $semesterId)
            ->where(function ($query) use ($seeds): void {
                foreach ($seeds->unique(static fn (Schedule $seed): string => $seed->section_id.':'.$seed->course_id) as $seed) {
                    $query->orWhere(fn ($class) => $class->where('section_id', $seed->section_id)->where('course_id', $seed->course_id));
                }
            })
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (Schedule $row): string => $row->section_id.':'.$row->course_id);
    }

    /** Why a class cannot be handed on individually right now, or null when it can. */
    private function classBlockReason(Collection $rows): ?string
    {
        return match (true) {
            $rows->contains(static fn (Schedule $row): bool => ! SchedulingPolicy::isDetachedClass($row))
                => 'Only a section returned by a receiving department can be reassigned on its own. Use Course Assignment for the whole course.',
            $rows->contains(static fn (Schedule $row): bool => $row->faculty !== null)
                => 'An instructor is already assigned to this section, so it stays where it is.',
            $rows->contains(static fn (Schedule $row): bool => ! in_array($row->status, SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES, true))
                => 'This section is not open for instructor assignment.',
            $rows->contains(static fn (Schedule $row): bool => (bool) $row->faculty_assignment_done)
                => 'Instructor assignments are marked done for this section.',
            default => null,
        };
    }

    /**
     * Writes the section-level teaching assignment under a row lock, re-checking each class so
     * two concurrent requests cannot both hand the same section on.
     *
     * @param  Collection<string, Collection<int, Schedule>>  $classes
     * @return list<int>|JsonResponse
     */
    private function writeSectionTeaching(Collection $classes, ?int $targetId, ?int $targetProgramId, ?User $actor, string $action): array|JsonResponse
    {
        $seeds = $classes->map(fn (Collection $rows) => $rows->first())->values();
        $semesterId = (int) $seeds->first()->semester_id;

        return DB::transaction(function () use ($seeds, $semesterId, $targetId, $targetProgramId, $actor, $action): array|JsonResponse {
            $locked = $this->classRows($seeds, $semesterId, true);
            if ($locked->contains(fn (Collection $rows): bool => $this->classBlockReason($rows) !== null)) {
                return response()->json(['message' => 'A selected section changed in the meantime. Refresh and try again.'], 409);
            }

            $scheduleIds = $locked->flatten(1)->pluck('id')->map('intval')->values()->all();
            $before = Schedule::query()->whereIn('id', $scheduleIds)->get();
            Schedule::query()->whereIn('id', $scheduleIds)->update([
                'section_teaching_department_id' => $targetId,
                'section_teaching_program_id' => $targetId === null ? null : $targetProgramId,
                'section_teaching_assigned_at' => $targetId === null ? null : now(),
                'faculty_assignment_done' => false,
            ]);
            $after = Schedule::query()->whereIn('id', $scheduleIds)->get();
            $version = app(ScheduleHistoryRecorder::class)->record($action, $before, $after, $actor?->id, $semesterId, $actor?->department_id === null ? null : (int) $actor->department_id, 'course_assignment');
            SchedulingAuditLog::create([
                'user_id' => $actor?->id,
                'semester_id' => $semesterId,
                'section_id' => $locked->count() === 1 ? $seeds->first()->section_id : null,
                'department_id' => $actor?->department_id,
                'action' => $action,
                'history_version_id' => $version->id,
                'metadata' => [
                    'schedule_ids' => $scheduleIds,
                    'section_ids' => $seeds->pluck('section_id')->map('intval')->unique()->values()->all(),
                    'course_ids' => $seeds->pluck('course_id')->map('intval')->unique()->values()->all(),
                    'teaching_department_id' => $targetId,
                    'teaching_program_id' => $targetId === null ? null : $targetProgramId,
                ],
                'created_at' => now(),
            ]);
            ApiCache::forgetGroups(['instructor_assignments.index', 'courses.index', 'initial.data']);

            return $scheduleIds;
        });
    }

    /** @param  Collection<string, Collection<int, Schedule>>  $classes */
    private function classLabels(Collection $classes): string
    {
        $labels = $classes->map(fn (Collection $rows): string => trim(($rows->first()->course?->course_code ?? 'Course').' ('.($rows->first()->section?->section_name ?? 'section').')'))->values();

        return $labels->take(5)->implode(', ').($labels->count() > 5 ? ' and '.($labels->count() - 5).' more' : '');
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

        // Only a live instructor holds the lock. Archiving keeps `faculty_id` for a restore,
        // but the course must not stay locked to an instructor who no longer exists.
        // A returned (detached) section is outside the course-level assignment, so it never
        // locks the course; it has its own lock (see sectionClasses).
        return Schedule::query()
            ->join('faculties', 'faculties.id', '=', 'schedules.faculty_id')
            ->whereNull('faculties.deleted_at')
            ->whereNull('schedules.delegation_returned_from_department_id')
            ->where('schedules.semester_id', $semester->id)
            ->whereIn('schedules.course_id', $courseIds)
            ->selectRaw('schedules.course_id, COUNT(DISTINCT schedules.section_id) AS classes')
            ->groupBy('schedules.course_id')
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
                $this->activeSemester()?->id,
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
    private function sectionAssignments(array $courseIds, int $departmentId, ?int $programId, array $excludedProgramIds = []): array
    {
        $semester = $this->activeSemester();
        if ($semester === null || $courseIds === []) {
            return [];
        }

        return Schedule::query()
            ->with(['section:id,section_name', 'faculty:id,first_name,last_name', 'returnedFromDepartment:id,department_code,department_name', 'sectionTeachingDepartment:id,department_code,department_name', 'sectionTeachingProgram:id,code,name,major'])
            ->where('semester_id', $semester->id)
            ->where('department_id', $departmentId)
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->when($excludedProgramIds !== [], fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('program_id')
                ->orWhereNotIn('program_id', $excludedProgramIds)))
            ->whereIn('course_id', $courseIds)
            ->get(['id', 'course_id', 'section_id', 'faculty_id', 'status', 'faculty_assignment_done', 'delegation_returned_from_department_id',
                'section_teaching_department_id', 'section_teaching_program_id'])
            ->groupBy('course_id')
            ->map(fn (Collection $schedules): array => $schedules->groupBy('section_id')
                ->map(function (Collection $meetings): array {
                    // `faculty` excludes archived instructors, so their meetings count as unassigned.
                    $instructors = $meetings->pluck('faculty')->filter()->unique('id')
                        ->map(fn ($faculty): string => trim("{$faculty->first_name} {$faculty->last_name}"))
                        ->filter()->values();
                    $unassigned = $meetings->contains(fn (Schedule $meeting): bool => $meeting->faculty === null);
                    $first = $meetings->first();
                    $returned = SchedulingPolicy::isDetachedClass($first);
                    $handedTo = $returned ? $first->sectionTeachingDepartment : null;
                    $staffing = $unassigned ? ($instructors->isEmpty() ? 'Awaiting instructor' : 'Partially assigned') : 'Assigned';

                    return [
                        'section_id' => (int) $first->section_id,
                        'schedule_id' => (int) $first->id,
                        'section_name' => $first->section?->section_name ?? 'Section',
                        'instructor_name' => $instructors->isEmpty() ? null : $instructors->implode(' / '),
                        'returned' => $returned,
                        'returned_from_department_id' => $returned ? (int) $first->delegation_returned_from_department_id : null,
                        'returned_from_department_code' => $returned ? $first->returnedFromDepartment?->department_code : null,
                        'section_teaching_department_id' => $handedTo === null ? null : (int) $handedTo->id,
                        'section_teaching_department_code' => $handedTo?->department_code,
                        'section_teaching_department_name' => $handedTo?->department_name,
                        'section_teaching_program_id' => $returned && $first->sectionTeachingProgram !== null ? (int) $first->sectionTeachingProgram->id : null,
                        'section_teaching_program_code' => $returned ? $first->sectionTeachingProgram?->shortLabel() : null,
                        // A returned section can be handed on (or taken back) only while nobody teaches it.
                        'reassignable' => $returned && $this->classBlockReason($meetings) === null,
                        'status' => match (true) {
                            $handedTo !== null => "Reassigned · {$staffing}",
                            $returned => $instructors->isEmpty() ? 'Returned to your department' : 'Returned · Assigned',
                            default => $staffing,
                        },
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

    private function presentIncoming(Course $course, int $departmentId): array
    {
        // Only the classes this department teaches now, decided per class: a section it returned
        // is gone, a returned section handed here on its own counts.
        $scheduleQuery = $course->schedules()->whereHas('academicSemester', fn ($query) => $query->where('is_active', true))
            ->delegatedTo($departmentId);
        $scheduleCount = (clone $scheduleQuery)->count();
        $unassignedCount = (clone $scheduleQuery)->whereDoesntHave('faculty')->count();

        return [
            'id' => (int) $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name,
            'source_department_id' => $course->department_id === null ? null : (int) $course->department_id,
            'source_department_code' => $course->department?->department_code, 'source_department_name' => $course->department?->department_name,
            'source_program_id' => $course->teaching_source_program_id === null ? null : (int) $course->teaching_source_program_id,
            'source_program_label' => $course->teachingSourceProgram?->shortLabel(),
            'teaching_department_id' => (int) $course->teaching_department_id, 'year_level' => $course->year_level === null ? null : (int) $course->year_level,
            'units' => $course->units, 'assignment_status' => $scheduleCount === 0 ? 'Awaiting schedule' : ($unassignedCount > 0 ? 'Instructor assignment pending' : 'Ready for teaching'),
            'schedule_count' => $scheduleCount,
            // Handed here section by section rather than as a whole course.
            'section_level' => (int) ($course->teaching_department_id ?? 0) !== $departmentId,
        ];
    }
}
