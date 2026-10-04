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
    public function __construct(private readonly SystemNotificationService $notifications) {}

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
        $incoming = Course::query()->with(['department', 'teachingDepartment', 'teachingProgram', 'teachingSourceProgram', 'program'])
            ->where('status', 'active')->delegatedTo($departmentId, $programId)
            ->when($activePeriod !== null, fn ($query) => $query->whereIn(
                'courses.id',
                $this->coursesPlacedInSemester($activePeriod),
            ))
            ->orderBy('course_code')->get()->map(fn (Course $course): array => $this->presentIncoming($course))->values();

        return response()->json([
            'current_department_id' => $departmentId,
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
                ->map(fn (Course $course): array => $this->present($course, $instructorClasses[(int) $course->id] ?? 0))
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

    public function update(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'teaching_department_id' => 'present|nullable|integer|exists:departments,id',
            'teaching_program_id' => 'sometimes|nullable|integer|exists:programs,id',
        ]);

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
            'course_ids.*' => ['integer', 'distinct', 'exists:courses,id'],
            'teaching_department_id' => ['required', 'integer', 'exists:departments,id'],
            'teaching_program_id' => ['nullable', 'integer', 'exists:programs,id'],
        ]);
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
        $count = $courses->count();
        $this->notifications->notifyRoles(
            ['secretary', 'program_head', 'dean'],
            'incoming_cross_department_courses',
            'Cross-department courses assigned',
            "{$source} assigned {$count} course".($count === 1 ? '' : 's')." to {$target}. View Cross-Department.",
            $actor,
            $targetId,
            null,
            null,
            ['course_ids' => $courses->pluck('id')->values()->all(), 'source_department_id' => $actor?->department_id, 'teaching_department_id' => $targetId, 'teaching_program_id' => $targetProgramId, 'link' => '/secretary/cross-department-assignments'],
        );

        return response()->json(['course_ids' => $courses->pluck('id')->values()->all()]);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        if ($locked = $this->refuseIfInstructorAssigned($course, null)) {
            return $locked;
        }

        $this->store($course, null, null, $request->user());

        return response()->json([
            'message' => 'Teaching college removed.',
            'course' => $this->present($course, 0),
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
        $previousTeachingDepartmentId = $course->teaching_department_id === null ? null : (int) $course->teaching_department_id;
        $course->teaching_department_id = $teachingDepartmentId;
        $course->teaching_program_id = $teachingProgramId;
        $course->teaching_source_program_id = $teachingDepartmentId === null
            ? null
            : ($this->sourceProgramIds(collect([$course]), $actor)[(int) $course->id] ?? null);
        $course->save();

        if ($teachingDepartmentId !== null && $teachingDepartmentId !== $previousTeachingDepartmentId) {
            $course->load(['department', 'teachingDepartment', 'teachingProgram', 'teachingSourceProgram']);
            $source = $this->sourceLabel(collect([$course]), $actor);
            $target = $course->teachingProgram?->shortLabel() ?? $course->teachingDepartment?->department_name;
            $this->notifications->notifyRoles(
                ['secretary', 'program_head', 'dean'],
                'incoming_cross_department_course',
                'Incoming cross-department course',
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
