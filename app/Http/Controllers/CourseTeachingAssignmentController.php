<?php

namespace App\Http\Controllers;

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

/**
 * Manages which college teaches a course, when that is not the college that owns it.
 *
 * Information Technology owns GEC 101; the College of Arts and Sciences teaches it.
 * A secretary or program head records that here, and the rule engine then holds
 * instructor assignment for GEC 101 to active CAS instructors — see
 * SchedulingPolicy::assignedTeachingDepartmentId and the
 * `service_subject_faculty_department_alignment` rule.
 *
 * The override is deliberately not scoped to the acting user's own department: any
 * secretary may decide who teaches a delegable course, whichever college owns it.
 * What is refused is delegating a **major**, which belongs to the department and
 * program that offers it.
 *
 * The *listing*, however, is department-specific, and its source is the department's
 * own curriculum. Like the Auto-Assign Instructor wizard it answers "what does my
 * curriculum offer", by year level, rather than listing every course in the
 * institution: a global list made the user hunt through other colleges' majors to
 * find their own minors. A course this college merely *teaches* for someone else is
 * not part of that answer — it belongs to the owner's curriculum, and it is reported
 * separately as an incoming cross-department course.
 *
 * It is also scoped to the active semester's period (1st, 2nd or summer), for the same reason
 * InitialDataController is: delegation is decided one semester at a time, and a list
 * carrying all three made the year-level tabs claim work that is not this semester's.
 */
class CourseTeachingAssignmentController extends Controller
{
    public function __construct(private readonly SystemNotificationService $notifications) {}

    /**
     * The courses of the acting department's curriculum, organised by year level,
     * with the override each currently carries.
     *
     * Majors are returned too, flagged `delegable: false`, so the management UI can
     * list them disabled with the reason rather than hiding them and leaving the
     * user wondering where the course went.
     */
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
        $incoming = Course::query()->with(['department', 'teachingDepartment', 'teachingProgram', 'program'])
            ->where('status', 'active')->where('teaching_department_id', $departmentId)
            ->where(fn ($query) => $query->whereNull('department_id')->orWhere('department_id', '!=', $departmentId))
            // An incoming course sits in the *owner's* curriculum, so its semester
            // has to be read from wherever it is placed rather than from this
            // department's curriculum, which does not carry it.
            ->when($activePeriod !== null, fn ($query) => $query->whereIn(
                'courses.id',
                $this->coursesPlacedInSemester($activePeriod),
            ))
            ->orderBy('course_code')->get()->map(fn (Course $course): array => $this->presentIncoming($course))->values();

        return response()->json([
            'current_department_id' => $departmentId,
            // An empty list means two different things — "your curriculum has no
            // minors left to delegate" and "you have not published a curriculum" —
            // and the page has to say which.
            'has_active_curriculum' => $curriculumIds->isNotEmpty(),
            // A third way to be empty: the curriculum is published but places
            // nothing this semester. The page has to name the semester it is showing,
            // or a narrowed list reads as a broken curriculum.
            'active_semester' => $activeSemester === null ? null : [
                'id' => (int) $activeSemester->id,
                'academic_year' => $activeSemester->academic_year,
                'semester' => $activeSemester->semester,
            ],
            'departments' => Departments::query()
                ->orderBy('department_name')
                ->get(['id', 'department_code', 'department_name', 'logo']),
            // Programs are assignment targets, so they must come from the receiving
            // colleges rather than the acting user's own department. The UI narrows
            // this list to whichever responsible department is currently selected.
            'programs' => Program::query()
                ->when($request->user()?->role !== 'program_head',
                    fn ($query) => $query->where('department_id', '!=', $departmentId))
                ->orderBy('department_id')
                ->orderBy('code')
                ->get(['id', 'department_id', 'code', 'name', 'cluster']),
            'incoming_cross_department_courses' => $incoming,
            'courses' => $courses
                ->map(fn (Course $course): array => $this->present($course, $instructorClasses[(int) $course->id] ?? 0))
                ->sortBy([['year_level', 'asc'], ['course_code', 'asc']])
                ->values(),
        ]);
    }

    /**
     * The curricula this department is currently running.
     *
     * Genuinely a set: a department mid-transition teaches its incoming cohort
     * from the new curriculum while the upper years finish on the old one, so
     * several are active at once. What any individual cohort follows is recorded
     * on the section, not inferred from this list.
     *
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

    /**
     * The semester currently being scheduled, from the same cache entry
     * InitialDataController reads, so the two pages cannot disagree about which
     * semester is live.
     */
    private function activeSemester(): ?Semester
    {
        return Cache::remember(
            ApiCache::key('semesters.active'),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => Semester::query()->where('is_active', true)->first(),
        );
    }

    /**
     * `semesters.semester` is the enum '1st'|'2nd'|'summer'; `curriculum_course.semester`
     * is an unsigned tinyint 1|2|3. Every comparison between the two has to cross
     * this gap, and a null means "do not narrow" — with no active semester the page
     * shows the whole curriculum rather than nothing at all.
     */
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
     * Every course any active curriculum places in the given semester.
     *
     * Used only for the incoming cross-department list, whose courses belong to
     * other colleges' curricula; the department's own list narrows against its own
     * curriculum instead, in departmentCourses().
     *
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
     * What this department's curriculum offers, year level included.
     *
     * The department's active curriculum is the only source — the same one the
     * scheduler and Auto-Assign read — so a course appears here if and only if that
     * curriculum places it, at the year level it places it at rather than the level
     * stored on the course record. A shared minor sits in a different year for every
     * college, so the stored level is the wrong answer for all but one of them.
     *
     * Two things make the list:
     *  - courses of the active curriculum that this department owns,
     *  - shared minors of that curriculum, which no college owns — GEC and GEE
     *    subjects, and precisely the ones this page exists to delegate.
     *
     * Courses another college delegated *to* this one are deliberately absent: they
     * belong to someone else's curriculum, and they have their own list in
     * `incoming_cross_department_courses`. Mixing them in would both contradict the
     * year-level grid, which is this curriculum's, and list them twice.
     *
     * With no active curriculum there is nothing to offer and nothing to place, so
     * the list is empty — the same answer InitialDataController gives. The page says
     * so rather than falling back to ownership, which for a college that owns no
     * minors would have returned every shared minor in the institution.
     *
     * Only the active semester's period (1st, 2nd or summer) is offered. A curriculum places a course in
     * exactly one semester, so this both narrows the list and picks which placement
     * the year level is read from, for the rare course placed twice.
     *
     * @param  Collection<int, int|string>  $curriculumIds
     * @return Collection<int, Course>
     */
    private function departmentCourses(int $departmentId, Collection $curriculumIds, ?int $activePeriod): Collection
    {
        if ($curriculumIds->isEmpty()) {
            return new Collection;
        }

        $placements = $this->curriculumPlacements($curriculumIds, $activePeriod);

        // A published curriculum that places nothing this semester offers nothing to
        // delegate — distinct from having no curriculum, which the response also says.
        if ($placements->isEmpty()) {
            return new Collection;
        }

        // Membership in the curriculum is already what $placements means, so the
        // course query narrows to those ids rather than repeating it as a whereHas.
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
     * Where this department's curriculum places each course, narrowed to the active
     * semester's period when there is one.
     *
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
            // Newest curriculum first, so the flattening below keeps its
            // placement. A department mid-transition legitimately runs two, and
            // this page's year tabs should follow the curriculum it is moving
            // to rather than the one it is retiring.
            ->orderByDesc('curriculum.effective_school_year')
            ->orderByDesc('curriculum_course.curriculum_id')
            ->get(['curriculum_course.course_id', 'curriculum_course.year_level', 'curriculum_course.curriculum_id', 'curriculum.name as curriculum_name', 'curriculum.program_id as curriculum_program_id', 'programs.code as curriculum_program_code', 'programs.name as curriculum_program_name', 'programs.cluster as curriculum_program_cluster'])
            // A course placed by both curricula appears once. The scalar year
            // level this collapses to is only a display default — anything that
            // schedules a cohort resolves the placement through that section's
            // own curriculum instead, via SectionCurriculumResolver.
            ->unique('course_id')
            ->keyBy('course_id');
    }

    /**
     * Rewrites each course's `year_level` to where the department's curriculum places
     * it, which is the level the page's year tabs are built from. Every course in the
     * list is placed by definition, so the guard here is only for the empty case.
     *
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
                $course->curriculum_program_cluster = $placement->curriculum_program_cluster;
            }
        });
    }

    /**
     * Record or change the teaching college for a course.
     *
     * A null `teaching_department_id` clears the override, which is the same effect
     * as destroy() — accepted here so the UI's single Save button can express both.
     */
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
        if ($teachingProgramId !== null) {
            $teachingDepartmentId = (int) Program::findOrFail($teachingProgramId)->department_id;
        }

        if ($teachingDepartmentId !== null && ! SchedulingPolicy::isDelegableCourse($course)) {
            return response()->json([
                'message' => 'A major course is taught by the department that offers it and cannot be assigned to another college.',
            ], 422);
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
        if ($targetProgramId !== null) {
            $targetId = (int) Program::findOrFail($targetProgramId)->department_id;
        }
        $courses = Course::query()->whereIn('id', $validated['course_ids'])->get();
        if ($courses->contains(fn (Course $course) => ! SchedulingPolicy::isDelegableCourse($course))) {
            return response()->json(['message' => 'Major courses cannot be delegated.'], 422);
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
        DB::transaction(fn () => $courses->each(function (Course $course) use ($targetId, $targetProgramId): void {
            $course->update(['teaching_department_id' => $targetId, 'teaching_program_id' => $targetProgramId]);
        }));
        $actor = $request->user();
        $source = $actor?->department?->department_name ?? 'A department';
        $target = Departments::find($targetId);
        $count = $courses->count();
        $this->notifications->notifyRoles(
            ['secretary', 'program_head', 'dean'],
            'incoming_cross_department_courses',
            'Cross-department courses assigned',
            "{$source} assigned {$count} course".($count === 1 ? '' : 's').' to your department. View Cross-Department.',
            $actor,
            $targetId,
            null,
            null,
            ['course_ids' => $courses->pluck('id')->values()->all(), 'source_department_id' => $actor?->department_id, 'teaching_department_id' => $targetId, 'link' => '/secretary/cross-department-assignments'],
        );

        return response()->json(['course_ids' => $courses->pluck('id')->values()->all()]);
    }

    /** Hand the course back to the derived rule — the college that owns it. */
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
     * Classes -- a course in one section -- that already have an instructor in
     * the active semester, keyed by course id.
     *
     * Once someone is teaching a course, the question "which college teaches it"
     * has been answered in practice. Handing it to another college then would
     * leave those classes with an instructor the new college never chose and the
     * rule engine now considers ineligible.
     *
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

    /**
     * The college that actually teaches the course under a given override: the
     * override itself, or the owner when none is recorded.
     */
    private function effectiveTeachingDepartmentId(Course $course, ?int $override): ?int
    {
        return $override ?? ($course->department_id === null ? null : (int) $course->department_id);
    }

    /**
     * Refuses a change of teaching college while any class of the course already
     * has an instructor. Saving the same college again is not a change and passes.
     */
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

    /**
     * Writes the override and drops the caches that answer with a teaching college,
     * so a picker cannot go on offering instructors from the previous one.
     */
    private function store(Course $course, ?int $teachingDepartmentId, ?int $teachingProgramId, ?User $actor = null): void
    {
        $previousTeachingDepartmentId = $course->teaching_department_id === null ? null : (int) $course->teaching_department_id;
        $course->teaching_department_id = $teachingDepartmentId;
        $course->teaching_program_id = $teachingProgramId;
        $course->save();

        if ($teachingDepartmentId !== null && $teachingDepartmentId !== $previousTeachingDepartmentId) {
            $course->loadMissing(['department', 'teachingDepartment']);
            $source = $course->department?->department_name ?? 'the source department';
            $this->notifications->notifyRoles(
                ['secretary', 'program_head', 'dean'],
                'incoming_cross_department_course',
                'Incoming cross-department course',
                "{$course->course_code} has been assigned to {$course->teachingDepartment?->department_name}. Source department: {$source}.",
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

        // The instructor-assignment workspace caches its whole payload — schedules,
        // eligible faculty and all — keyed by department. Both the old and the new
        // teaching college now answer differently, and the group version is global,
        // so one bump covers every department's entry.
        ApiCache::forgetGroups(['instructor_assignments.index', 'courses.index', 'initial.data']);

        $course->load(['department', 'teachingDepartment', 'teachingProgram', 'program']);
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
            'program_cluster' => $course->program?->cluster,
            'curriculum_program_id' => $course->getAttribute('curriculum_program_id') === null
                ? null
                : (int) $course->getAttribute('curriculum_program_id'),
            'curriculum_program_code' => $course->getAttribute('curriculum_program_code'),
            'curriculum_program_name' => $course->getAttribute('curriculum_program_name'),
            'curriculum_program_cluster' => $course->getAttribute('curriculum_program_cluster'),
            'delegable' => SchedulingPolicy::isDelegableCourse($course),
            // Classes this semester that already have an instructor. While any do,
            // the teaching college cannot be changed.
            'instructor_assigned_classes' => $instructorAssignedClasses,
            // The college that ends up teaching it once the fallback is applied, so
            // the UI can show the effective answer next to the stored override.
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
            'teaching_department_id' => (int) $course->teaching_department_id, 'year_level' => $course->year_level === null ? null : (int) $course->year_level,
            'units' => $course->units, 'assignment_status' => $scheduleCount === 0 ? 'Awaiting schedule' : ($unassignedCount > 0 ? 'Instructor assignment pending' : 'Ready for teaching'),
            'schedule_count' => $scheduleCount,
        ];
    }
}
