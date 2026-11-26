<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Concerns\EnforcesFacultyUnitCeiling;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\FacultyLoadService;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Department\CrossDepartmentApprovalGate;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Schedule\ManualHybridFacultyAssignmentResolver;
use App\Services\Scheduling\Schedule\ProgramCourseVisibility;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InstructorAssignmentController extends Controller
{
    use EnforcesFacultyUnitCeiling;

    private const ASSIGNABLE_STATUSES = SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES;

    private const VISIBLE_STATUSES = SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES;

    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
        private readonly FacultyLoadService $facultyLoad,
        private readonly ManualHybridFacultyAssignmentResolver $manualHybridAssignments,
        private readonly ScheduleHistoryRecorder $historyRecorder,
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
            ? (int) ($request->user()?->program_id ?? 0)
            : null;

        $cacheKey = ApiCache::compositeKey('instructor_assignments.index', [
            'departments.index', 'courses.index', 'faculty.index', 'rooms.index',
            'sections.index', 'semesters.index', 'initial.data', 'initial.data.schedules',
        ], [
            'department_id' => $departmentId,
            'program_id' => $programId,
            'version' => 6,
        ]);

        $data = Cache::remember($cacheKey, ApiCache::LOOKUP_TTL_SECONDS, function () use ($departmentId, $programId) {
            $activeSemester = Semester::query()->where('is_active', true)->first();

            if (! $activeSemester) {
                return [
                    'active_semester' => null,
                    'current_department_id' => $departmentId,
                    'departments' => [],
                    'subjects' => [],
                    'faculties' => [],
                    'schedules' => [],
                ];
            }

            $schedules = Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->where('semester_id', $activeSemester->id)
                // Only VPAA-approved rows. A row still in Draft, Submitted or Dean review stays
                // out of view even when it already carries a (retained) instructor.
                ->whereIn('status', self::VISIBLE_STATUSES)
                ->whereHas('course', fn ($query) => $query->where('status', 'active'))
                ->where(function ($query) use ($departmentId) {
                    $query->where('department_id', $departmentId)
                        ->orWhere(fn ($delegated) => $delegated->delegatedTo($departmentId));
                })
                // Handed to the program per class (course-level, or section-level once returned).
                ->when($programId !== null, fn ($query) => $query->where(fn ($scoped) => $scoped
                    ->whereHas('course', fn ($course) => $course->where('program_id', $programId))
                    ->orWhere(fn ($handed) => $handed->handedToProgram($programId))))
                ->orderBy('department_id')
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();

            // Another college's delegated classes enter Cross-Department only once the VPAA has
            // approved that college's whole timetable — the same gate that opens its Course
            // Assignment. Partially approved or recalled colleges stay out of view; any kept
            // instructors remain recorded.
            $unapproved = $this->approvalGate->awaitingApproval(
                $schedules->pluck('department_id')->reject(fn ($id): bool => (int) $id === $departmentId),
                (int) $activeSemester->id,
            );
            if ($unapproved !== []) {
                $schedules = $schedules
                    ->reject(fn (Schedule $schedule): bool => in_array((int) $schedule->department_id, $unapproved, true))
                    ->values();
            }

            $faculties = Faculty::query()
                ->with(['department', 'program', 'availabilities'])
                ->where('department_id', $departmentId)
                ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();

            $this->facultyLoad->decorateMany($faculties, (int) $activeSemester->id, true);

            $courses = $schedules->pluck('course')->filter()->unique('id')->values();

            $departments = Departments::query()
                ->whereIn('id', $schedules->pluck('department_id')->filter()->unique())
                ->orderBy('id')
                ->get();
            $schedules->each(fn (Schedule $schedule) => $schedule->department?->makeHidden('logo'));
            $courses->each(fn (Course $course) => $course->department?->makeHidden('logo'));
            $faculties->each(fn (Faculty $faculty) => $faculty->department?->makeHidden('logo'));

            return [
                'active_semester' => $activeSemester,
                'current_department_id' => $departmentId,
                'departments' => $departments,
                'courses' => $courses,
                'subjects' => $courses,
                'faculties' => $faculties,
                'schedules' => $schedules,
            ];
        });

        $rows = collect($data['schedules'] ?? []);
        $hidden = app(ProgramCourseVisibility::class)->hiddenInstructorScheduleIds($request, $rows);
        $data['schedules'] = $rows->reject(fn (Schedule $row): bool => in_array((int) $row->id, $hidden, true))->values();
        $visibleCourses = $data['schedules']->pluck('course_id')->unique()->all();
        foreach (['courses', 'subjects'] as $key) {
            $data[$key] = collect($data[$key] ?? [])->filter(fn (Course $course): bool => in_array($course->id, $visibleCourses))->values();
        }
        $visibleDepartments = $data['schedules']->pluck('department_id')->unique()->all();
        $data['departments'] = collect($data['departments'] ?? [])
            ->filter(fn (Departments $department): bool => in_array($department->id, $visibleDepartments))->values();

        return response()->json($data);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'faculty_id' => 'present|nullable|integer|exists:faculties,id',
        ]);

        $departmentId = (int) ($request->user()?->department_id ?? 0);
        // Per class: a section the teaching college returned follows the offering department's rules.
        $course = SchedulingPolicy::courseForSchedule($schedule);
        $teachingDepartmentId = SchedulingPolicy::scheduleTeachingDepartmentId($schedule);

        if (! $course) {
            return response()->json([
                'message' => 'Only the college that offers this course can assign its instructor.',
            ], 403);
        }

        $isMajor = SchedulingPolicy::isMajorCourse($course);

        if ($request->user()?->role === 'program_head') {
            $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($course);
            if ($requiredProgramId === null || $requiredProgramId !== (int) ($request->user()?->program_id ?? 0)) {
                return response()->json([
                    'message' => 'Program Heads can only assign courses assigned to their program.',
                ], 403);
            }
        }

        if ((int) $teachingDepartmentId !== $departmentId) {
            return response()->json([
                'message' => $isMajor
                    ? 'Only the department that offers this major can assign its instructor.'
                    : 'Only the college that offers this course can assign its instructor.',
            ], 403);
        }

        if (app(ProgramCourseVisibility::class)->hiddenInstructorScheduleIds($request, [$schedule]) !== []) {
            return response()->json(['message' => ProgramCourseVisibility::ASSIGNED_ELSEWHERE], 403);
        }

        if ($this->authorization->majorInstructorAssignmentBlocked($request, [$schedule])) {
            return response()->json(['message' => ScheduleAuthorizationService::MAJOR_INSTRUCTOR_FORBIDDEN_MESSAGE], 403);
        }

        if (! in_array($schedule->status, self::ASSIGNABLE_STATUSES, true)) {
            return response()->json([
                'message' => $schedule->status === 'finalized'
                    ? 'A finalized schedule cannot be reassigned.'
                    : 'Instructor assignment is available only after VPAA approval.',
            ], 422);
        }

        $facultyId = $validated['faculty_id'] === null ? null : (int) $validated['faculty_id'];
        $faculty = $facultyId === null ? null : Faculty::query()->findOrFail($facultyId);
        if ($faculty !== null && ((int) $faculty->department_id !== $departmentId || $faculty->status !== 'active')) {
            return response()->json([
                'message' => 'The selected instructor must be active and belong to the college that teaches this course.',
            ], 422);
        }

        if (
            $request->user()?->role === 'program_head'
            && $faculty !== null
            && (int) $faculty->program_id !== (int) ($request->user()?->program_id ?? 0)
        ) {
            return response()->json([
                'message' => 'Program Heads can only assign instructors from their assigned program.',
            ], 422);
        }

        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($course);
        if ($faculty !== null && $requiredProgramId !== null && (int) $faculty->program_id !== $requiredProgramId) {
            $requiredProgram = Program::query()->find($requiredProgramId);
            $programLabel = $requiredProgram?->code ?? $requiredProgram?->name;

            return response()->json([
                'message' => $programLabel !== null
                    ? ($isMajor
                        ? "This major belongs to the {$programLabel} program, so only instructors of that program can be assigned."
                        : "This course is assigned to the {$programLabel} program, so only instructors of that program can be assigned.")
                    : 'This course is assigned to a program the selected instructor is not assigned to.',
            ], 422);
        }

        $linkedSchedules = $this->linkedMeetingBlocks($schedule);
        $linkedScheduleIds = $linkedSchedules->pluck('id')->all();
        $violations = [];

        foreach ($linkedSchedules as $linkedSchedule) {
            $attempt = array_merge($linkedSchedule->toArray(), [
                'faculty_id' => $facultyId,
                'ignore_schedule_id' => $linkedScheduleIds,
            ]);
            $linkedViolations = $this->ruleEngine->validateInstructorAssignment($attempt);
            $violations = array_merge($violations, $linkedViolations);
        }

        if ($violations !== []) {
            return response()->json([
                'message' => 'The instructor assignment conflicts with an existing schedule.',
                'violations' => $violations,
            ], 422);
        }

        $activeSemesterId = $this->activeSemesterId();
        $projection = null;
        if ($faculty !== null) {
            $incoming = array_values(array_filter([$this->loadPairForSchedule($schedule)]));
            $projection = $this->withAssignmentLabel(
                $this->facultyLoad->projectLoad($faculty, $activeSemesterId, $incoming),
                $this->assignmentLabelForSchedule($schedule),
            );

            $refusal = $this->unitCeilingRefusal([$projection]);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        $previousFacultyId = $schedule->faculty_id;
        $updatedSchedules = DB::transaction(function () use (
            $request,
            $linkedSchedules,
            $linkedScheduleIds,
            $facultyId,
            $previousFacultyId,
            $departmentId,
            $projection,
        ) {
            $before = Schedule::query()->whereIn('id', $linkedScheduleIds)->get();
            // Removing the instructor also releases the handoff's "done" lock, so the class
            // is open for reassignment instead of staying done with nobody assigned.
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->update($facultyId === null
                    ? ['faculty_id' => null, 'faculty_assignment_done' => false]
                    : ['faculty_id' => $facultyId]);

            $after = Schedule::query()->whereIn('id', $linkedScheduleIds)->get();
            $action = $facultyId === null ? 'instructor_assignment_released' : 'instructor_assigned';
            $version = $this->historyRecorder->record($action, $before, $after, $request->user()?->id, $linkedSchedules->first()->semester_id, $departmentId, 'instructor_assignment');
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'semester_id' => $linkedSchedules->first()->semester_id,
                'section_id' => $linkedSchedules->first()->section_id,
                'department_id' => $departmentId,
                'action' => $action,
                'history_version_id' => $version->id,
                'metadata' => [
                    'schedule_id' => $linkedSchedules->first()->id,
                    'schedule_ids' => $linkedScheduleIds,
                    'course_id' => $linkedSchedules->first()->course_id,
                    'previous_faculty_id' => $previousFacultyId,
                    'faculty_id' => $facultyId,
                    'reason' => $facultyId === null ? 'manual_removal' : 'manual_assignment',
                    'pro_bono' => (bool) ($projection['pro_bono'] ?? false),
                    'offering_department_id' => $linkedSchedules->first()->department_id,
                ],
                'created_at' => now(),
            ]);

            return Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->whereIn('id', $linkedScheduleIds)
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();
        });

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        if ($request->user() && $linkedSchedules->contains(fn (Schedule $row): bool => ($row->faculty_id === null ? null : (int) $row->faculty_id) !== $facultyId)) {
            $this->notifications->notifyInstructorAssignmentProgress($updatedSchedules->first(), $request->user(), $updatedSchedules->modelKeys());
        }

        $load = $faculty === null
            ? null
            : $this->facultyLoad->projectLoad($faculty->refresh(), $activeSemesterId, []);

        $proBonoNotice = $projection === null ? null : $this->proBonoNotice($projection);
        if ($load !== null && $proBonoNotice !== null) {
            $load = array_merge($load, [
                'tier' => SchedulingPolicy::LOAD_TIER_PRO_BONO,
                'tier_label' => SchedulingPolicy::loadTierLabel(SchedulingPolicy::LOAD_TIER_PRO_BONO),
                'pro_bono' => true,
                'pro_bono_units' => (int) $projection['pro_bono_units'],
            ]);
        }

        return response()->json(array_filter([
            'schedule' => $updatedSchedules->first(),
            'schedules' => $updatedSchedules,
            'warnings' => [],
            'load' => $load,
            'message' => $proBonoNotice,
        ], static fn ($value, string $key): bool => $key !== 'message' || $value !== null, ARRAY_FILTER_USE_BOTH));
    }

    public function recommendations(Request $request, Schedule $schedule): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if (! $this->userCanManageInstructor($request, $schedule, $departmentId)) {
            return response()->json(['message' => 'You cannot assign an instructor to this class.'], 403);
        }

        return response()->json([
            'code' => 'instructor_recommendations_retired',
            'message' => 'Instructor recommendations have been retired. Choose an instructor manually in Instructor Assignment.',
            'options' => [],
        ], 410);
    }

    public function clearSection(Request $request, Sections $section): JsonResponse
    {
        return $this->clearSectionInstructors($request, collect([$section]));
    }

    public function clearSections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct', 'exists:sections,id'],
        ]);

        return $this->clearSectionInstructors(
            $request,
            Sections::query()->whereIn('id', $validated['section_ids'])->get(),
        );
    }

    /**
     * @param  Collection<int, Sections>  $sections
     */
    private function clearSectionInstructors(Request $request, Collection $sections): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        $activeSemesterId = $this->activeSemesterId();
        if ($activeSemesterId === null || $sections->contains(
            static fn (Sections $section): bool => (int) $section->semester_id !== $activeSemesterId
        )) {
            return response()->json(['message' => 'Instructor assignments can only be cleared for the active semester.'], 422);
        }

        $sectionIds = $sections->pluck('id')->map('intval')->values()->all();
        $section = $sections->count() === 1 ? $sections->first() : null;

        $sectionSchedules = Schedule::query()
            ->with('course')
            ->where('semester_id', $activeSemesterId)
            ->whereIn('section_id', $sectionIds)
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->where('faculty_assignment_done', false)
            ->whereNotNull('faculty_id')
            ->get();
        $targetSchedules = $sectionSchedules
            ->filter(fn (Schedule $schedule): bool => $this->userCanManageInstructor($request, $schedule, $departmentId))
            ->values();

        if ($targetSchedules->isEmpty()) {
            return response()->json([
                'message' => $section !== null
                    ? 'This section has no instructor assignments that your account can clear.'
                    : 'These sections have no instructor assignments that your account can clear.',
            ], 422);
        }

        $scheduleIds = $targetSchedules->pluck('id')->map('intval')->values()->all();
        $facultyIds = $targetSchedules->pluck('faculty_id')->filter()->map('intval')->unique()->values();
        $previousFacultyIds = $targetSchedules->mapWithKeys(
            static fn (Schedule $schedule): array => [(string) $schedule->id => (int) $schedule->faculty_id]
        )->all();

        $coursesCleared = $targetSchedules
            ->map(static fn (Schedule $schedule): string => $schedule->section_id.'-'.$schedule->course_id)
            ->unique()
            ->count();

        $updatedSchedules = DB::transaction(function () use (
            $request,
            $targetSchedules,
            $coursesCleared,
            $scheduleIds,
            $previousFacultyIds,
            $facultyIds,
            $departmentId,
            $activeSemesterId,
            $section,
            $sectionIds,
        ) {
            $before = Schedule::query()->whereIn('id', $scheduleIds)->get();
            Schedule::query()->whereIn('id', $scheduleIds)->update(['faculty_id' => null]);
            $after = Schedule::query()->whereIn('id', $scheduleIds)->get();
            $version = $this->historyRecorder->record(
                'instructor_assignment_released',
                $before,
                $after,
                $request->user()?->id,
                $activeSemesterId,
                $departmentId,
                'instructor_assignment',
            );
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'semester_id' => $activeSemesterId,
                'section_id' => $section?->id,
                'department_id' => $departmentId,
                'action' => 'instructor_assignment_released',
                'history_version_id' => $version->id,
                'metadata' => [
                    'reason' => $section !== null ? 'section_clear' : 'sections_clear',
                    'section_ids' => $sectionIds,
                    'schedule_ids' => $scheduleIds,
                    'course_ids' => $targetSchedules->pluck('course_id')->map('intval')->unique()->values()->all(),
                    'previous_faculty_ids' => $previousFacultyIds,
                    'faculty_ids' => $facultyIds->all(),
                    'schedules_updated' => count($scheduleIds),
                    'courses_cleared' => $coursesCleared,
                    'offering_department_ids' => $targetSchedules->pluck('department_id')->map('intval')->unique()->values()->all(),
                ],
                'created_at' => now(),
            ]);

            return Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->whereIn('id', $scheduleIds)
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();
        });

        $affectedFaculties = Faculty::query()
            ->with(['department', 'program', 'availabilities'])
            ->whereIn('id', $facultyIds->all())
            ->get();
        $this->facultyLoad->decorateMany($affectedFaculties, $activeSemesterId, true);
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => $section !== null
                ? 'All eligible instructor assignments for the section were cleared.'
                : 'All eligible instructor assignments for the selected sections were cleared.',
            'section_id' => $section?->id,
            'section_ids' => $sectionIds,
            'schedules_updated' => $updatedSchedules->count(),
            'courses_cleared' => $coursesCleared,
            'schedules' => $updatedSchedules,
            'faculties' => $affectedFaculties,
        ]);
    }

    /**
     * A receiving department hands delegated classes it cannot staff back to the department
     * that offers them. Each id stands for its whole class (every meeting of that section's
     * course). Only classes with no live instructor may go back; a staffed class stays with
     * the receiving department. Returned classes then follow the offering department's own
     * instructor-assignment rules (SchedulingPolicy::courseForRow).
     */
    public function returnSections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_ids' => ['required', 'array', 'min:1', 'max:500'],
            'schedule_ids.*' => ['integer', 'distinct', 'exists:schedules,id'],
        ]);

        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        $activeSemesterId = $this->activeSemesterId();
        $seeds = Schedule::query()->whereIn('id', $validated['schedule_ids'])->get(['id', 'semester_id', 'section_id', 'course_id']);
        if ($activeSemesterId === null || $seeds->contains(static fn (Schedule $seed): bool => (int) $seed->semester_id !== $activeSemesterId)) {
            return response()->json(['message' => 'Sections can only be returned for the active semester.'], 422);
        }

        $classes = Schedule::query()
            ->with(['course.department', 'course.teachingDepartment', 'section', 'faculty'])
            ->where('semester_id', $activeSemesterId)
            ->where(function ($query) use ($seeds): void {
                foreach ($seeds->unique(static fn (Schedule $seed): string => $seed->section_id.':'.$seed->course_id) as $seed) {
                    $query->orWhere(fn ($class) => $class->where('section_id', $seed->section_id)->where('course_id', $seed->course_id));
                }
            })
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (Schedule $row): string => $row->section_id.':'.$row->course_id);

        // The caller must currently teach every class through a delegation it has not returned.
        $notOurs = $classes->filter(fn (Collection $rows): bool => $rows->contains(fn (Schedule $row): bool => ! $this->isDelegatedHere($row, $departmentId)
            || ! $this->userCanManageInstructor($request, $row, $departmentId)));
        if ($notOurs->isNotEmpty()) {
            return response()->json([
                'message' => 'You can only return sections that another department assigned to yours and that you may manage.',
            ], 403);
        }

        $blocked = $classes->map(function (Collection $rows): ?array {
            $first = $rows->first();
            $reason = match (true) {
                $rows->contains(static fn (Schedule $row): bool => $row->faculty !== null) => 'An instructor is already assigned, so this section stays with your department.',
                $rows->contains(static fn (Schedule $row): bool => ! in_array($row->status, self::ASSIGNABLE_STATUSES, true)) => 'This section is not open for instructor assignment.',
                $rows->contains(static fn (Schedule $row): bool => (bool) $row->faculty_assignment_done) => 'Instructor assignments are marked done. Choose Edit Assignments first.',
                default => null,
            };

            return $reason === null ? null : [
                'section_id' => (int) $first->section_id,
                'section_name' => $first->section?->section_name,
                'course_id' => (int) $first->course_id,
                'course_code' => $first->course?->course_code,
                'reason' => $reason,
            ];
        })->filter()->values();
        if ($blocked->isNotEmpty()) {
            return response()->json([
                'message' => $blocked->count() === 1
                    ? "{$blocked[0]['course_code']} ({$blocked[0]['section_name']}): {$blocked[0]['reason']}"
                    : 'Some selected sections cannot be returned. Only sections without an assigned instructor can be returned.',
                'blocked' => $blocked->all(),
            ], 422);
        }

        $rows = $classes->flatten(1)->values();
        $scheduleIds = $rows->pluck('id')->map('intval')->values()->all();
        $actor = $request->user();
        DB::transaction(function () use ($rows, $scheduleIds, $departmentId, $activeSemesterId, $actor): void {
            $before = Schedule::query()->whereIn('id', $scheduleIds)->get();
            // An archived instructor's id goes too: the class is no longer this department's to restore.
            // The class is detached from the course-level delegation and any section-level
            // target is cleared: it is the offering department's again, to keep or hand on.
            Schedule::query()->whereIn('id', $scheduleIds)->lockForUpdate()->get();
            Schedule::query()->whereIn('id', $scheduleIds)->update([
                'delegation_returned_from_department_id' => $departmentId,
                'delegation_returned_at' => now(),
                'section_teaching_department_id' => null,
                'section_teaching_program_id' => null,
                'section_teaching_assigned_at' => null,
                'faculty_id' => null,
                'faculty_assignment_done' => false,
            ]);
            $after = Schedule::query()->whereIn('id', $scheduleIds)->get();
            $version = $this->historyRecorder->record('cross_department_sections_returned', $before, $after, $actor?->id, $activeSemesterId, $departmentId, 'instructor_assignment');
            SchedulingAuditLog::create([
                'user_id' => $actor?->id,
                'semester_id' => $activeSemesterId,
                'section_id' => $rows->pluck('section_id')->unique()->count() === 1 ? $rows->first()->section_id : null,
                'department_id' => $departmentId,
                'action' => 'cross_department_sections_returned',
                'history_version_id' => $version->id,
                'metadata' => [
                    'schedule_ids' => $scheduleIds,
                    'section_ids' => $rows->pluck('section_id')->map('intval')->unique()->values()->all(),
                    'course_ids' => $rows->pluck('course_id')->map('intval')->unique()->values()->all(),
                    'offering_department_ids' => $rows->pluck('department_id')->map('intval')->unique()->values()->all(),
                ],
                'created_at' => now(),
            ]);
        });

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data', 'courses.index']);
        if ($actor !== null) {
            $this->notifySectionsReturned($classes, $actor, $activeSemesterId);
        }

        return response()->json([
            'message' => $classes->count() === 1
                ? '1 section was returned to the offering department.'
                : "{$classes->count()} sections were returned to the offering department.",
            'schedule_ids' => $scheduleIds,
            'sections_returned' => $classes->count(),
            'schedules' => Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->whereIn('id', $scheduleIds)
                ->orderBy('day')
                ->orderBy('start_time')
                ->get(),
        ]);
    }

    /** The class reached this department through a delegation, course- or section-level. */
    private function isDelegatedHere(Schedule $row, int $departmentId): bool
    {
        $course = SchedulingPolicy::courseForSchedule($row);
        if ($course === null || (int) ($course->teaching_department_id ?? 0) !== $departmentId) {
            return false;
        }

        // Within one department, only a hand-over to another program is a delegation.
        return (int) $row->department_id !== $departmentId || $course->teaching_program_id !== null;
    }

    /**
     * @param  Collection<string, Collection<int, Schedule>>  $classes
     */
    private function notifySectionsReturned(Collection $classes, User $actor, int $semesterId): void
    {
        $classes->flatten(1)
            ->groupBy(static fn (Schedule $row): int => (int) $row->department_id)
            ->each(function (Collection $rows, int $offeringDepartmentId) use ($actor, $semesterId): void {
                $first = $rows->first();
                $labels = $rows->unique(static fn (Schedule $row): string => $row->section_id.':'.$row->course_id)
                    ->map(static fn (Schedule $row): string => trim(($row->course?->course_code ?? 'Course').' ('.($row->section?->section_name ?? 'section').')'))
                    ->values();
                // The returning department is the actor's (course-level or section-level receiver).
                $receiving = $actor->department?->department_name ?? 'The teaching department';
                $label = $labels->take(5)->implode(', ').($labels->count() > 5 ? ' and '.($labels->count() - 5).' more' : '');
                $this->notifications->notifyRoles(
                    ['secretary', 'program_head'],
                    'cross_department_sections_returned',
                    'Cross-department sections returned',
                    "{$receiving} could not assign an instructor and returned {$label}. Assign them yourself, or hand them to another department in Course Assignment.",
                    $actor,
                    $offeringDepartmentId,
                    $semesterId,
                    null,
                    [
                        'schedule_ids' => $rows->pluck('id')->map('intval')->values()->all(),
                        'course_ids' => $rows->pluck('course_id')->map('intval')->unique()->values()->all(),
                        'receiving_department_id' => (int) ($actor->department_id ?? 0),
                    ],
                    $rows->map(static fn (Schedule $row) => $row->program_id ?? $row->section?->program_id)->filter()->map('intval')->unique()->values()->all() ?: null,
                );
            });
    }

    private function linkedMeetingBlocks(Schedule $schedule)
    {
        $hybridComponents = $this->manualHybridAssignments->resolve($schedule)
            ->filter(fn (Schedule $component): bool => in_array($component->status, self::ASSIGNABLE_STATUSES, true))
            ->values();

        if ($hybridComponents->count() > 1) {
            return $hybridComponents;
        }

        return Schedule::query()
            ->where('semester_id', $schedule->semester_id)
            ->where('section_id', $schedule->section_id)
            ->where('course_id', $schedule->course_id)
            ->where('department_id', $schedule->department_id)
            ->where('preferred_pattern', $schedule->preferred_pattern)
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->get();
    }

    private function userCanManageInstructor(Request $request, Schedule $schedule, int $departmentId): bool
    {
        if (app(ProgramCourseVisibility::class)->hiddenInstructorScheduleIds($request, [$schedule]) !== []) {
            return false;
        }
        $course = SchedulingPolicy::courseForSchedule($schedule);
        if ($course === null) {
            return false;
        }

        if ((int) SchedulingPolicy::scheduleTeachingDepartmentId($schedule) !== $departmentId) {
            return false;
        }

        if ($this->authorization->majorInstructorAssignmentBlocked($request, [$schedule])) {
            return false;
        }

        if ($request->user()?->role !== 'program_head') {
            return true;
        }

        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($course);

        return $requiredProgramId !== null
            && $requiredProgramId === (int) ($request->user()?->program_id ?? 0);
    }
}
