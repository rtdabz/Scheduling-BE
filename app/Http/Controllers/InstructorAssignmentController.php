<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsFacultyOverload;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\FacultyLoadService;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Schedule\FacultyConflictOverride;
use App\Services\Scheduling\Schedule\ManualHybridFacultyAssignmentResolver;
use App\Services\Scheduling\Engine\RuleEngine;
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
    use ConfirmsFacultyOverload;

    private const ASSIGNABLE_STATUSES = SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES;

    private const VISIBLE_STATUSES = SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES;

    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
        private readonly FacultyLoadService $facultyLoad,
        private readonly ManualHybridFacultyAssignmentResolver $manualHybridAssignments,
        private readonly ScheduleHistoryRecorder $historyRecorder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        // Program heads operate inside one program even when several programs
        // share the same college. A missing program assignment intentionally
        // produces no faculty candidates instead of widening to the department.
        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : null;

        $cacheKey = ApiCache::key('instructor_assignments.index', [
            'department_id' => $departmentId,
            'program_id' => $programId,
            // The payload includes delegated courses and source-department timetable
            // rows; bump this when that dataset changes so old empty responses cannot
            // hide a newly assigned cross-department course.
            'version' => 3,
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
                    'incoming_courses' => [],
                ];
            }

            $incomingCourses = Course::query()
                ->with(['department:id,department_code,department_name'])
                ->where('status', 'active')
                ->where('teaching_department_id', $departmentId)
                ->when($programId !== null, fn ($query) => $query->where(
                    fn ($programScope) => $programScope
                        ->where('program_id', $programId)
                        ->orWhere('teaching_program_id', $programId),
                ))
                ->where(function ($query) use ($departmentId): void {
                    $query->whereNull('department_id')->orWhere('department_id', '!=', $departmentId);
                })
                ->orderBy('course_code')
                ->get(['id', 'course_code', 'course_name', 'units', 'year_level', 'department_id', 'teaching_department_id']);

            $schedules = Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->where('semester_id', $activeSemester->id)
                ->whereIn('status', self::VISIBLE_STATUSES)
                ->whereHas('course', fn ($query) => $query->where('status', 'active'))
                // Own offerings, plus anything another college has delegated to this
                // one: IT owns GEC 101 but CAS teaches it, so the CAS workspace has
                // to show IT's GEC 101 offerings for CAS to be able to assign them.
                // Only an explicit override widens this — a GEC course owned by this
                // department already matches on `department_id`.
                ->where(function ($query) use ($departmentId) {
                    $query->where('department_id', $departmentId)
                        ->orWhereHas(
                            'course',
                            fn ($course) => $course->where('teaching_department_id', $departmentId),
                        );
                })
                ->when($programId !== null, fn ($query) => $query->whereHas(
                    'course',
                    fn ($course) => $course
                        ->where('program_id', $programId)
                        ->orWhere('teaching_program_id', $programId),
                ))
                ->orderBy('department_id')
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();

            $faculties = Faculty::query()
                ->with(['department', 'program', 'availabilities'])
                ->where('department_id', $departmentId)
                ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();

            // The picker shows each instructor's live load so an overload is
            // visible before Save is pressed, and the tier badge needs the same
            // numbers the confirmation gate projects from.
            $this->facultyLoad->decorateMany($faculties, (int) $activeSemester->id);

            $courses = $schedules->pluck('course')->filter()->unique('id')->values();

            return [
                'active_semester' => $activeSemester,
                'current_department_id' => $departmentId,
                'departments' => $schedules->pluck('department')->filter()->unique('id')->values(),
                'courses' => $courses,
                'subjects' => $courses,
                'faculties' => $faculties,
                'schedules' => $schedules,
                'incoming_courses' => $incomingCourses,
            ];
        });

        return response()->json($data);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'faculty_id' => 'present|nullable|integer|exists:faculties,id',
        ]);

        $departmentId = (int) ($request->user()?->department_id ?? 0);
        // For a major the offering department is the only one that can assign; a GEC
        // service course is assigned by the college that offers it.
        $teachingDepartmentId = $schedule->course
            ? (SchedulingPolicy::isMajorCourse($schedule->course)
                ? SchedulingPolicy::majorTeachingDepartmentId($schedule->course, (int) $schedule->department_id)
                : SchedulingPolicy::assignedTeachingDepartmentId($schedule->course) ?? (int) $schedule->department_id)
            : null;

        if (! $schedule->course) {
            return response()->json([
                'message' => 'Only the college that offers this course can assign its instructor.',
            ], 403);
        }

        $isMajor = SchedulingPolicy::isMajorCourse($schedule->course);

        if ($request->user()?->role === 'program_head') {
            $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($schedule->course);
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

        // Checked here as well as in the rule engine so the workspace can say why
        // the instructor is ineligible instead of reporting a generic conflict.
        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($schedule->course);
        if ($faculty !== null && $requiredProgramId !== null && (int) $faculty->program_id !== $requiredProgramId) {
            $schedule->course->loadMissing(['program', 'teachingProgram']);
            $requiredProgram = SchedulingPolicy::isMajorCourse($schedule->course)
                ? $schedule->course->program
                : $schedule->course->teachingProgram;
            $programLabel = $requiredProgram?->code ?? $requiredProgram?->name;

            return response()->json([
                'message' => $programLabel !== null
                    ? (SchedulingPolicy::isMajorCourse($schedule->course)
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
            $violations = array_merge($violations, $this->ruleEngine->validateInstructorAssignment($attempt));
        }

        // The instructor's own clashes may be assigned over on purpose; anything
        // else still refuses. Both sides of an overridden clash are marked below.
        $overriddenIds = [];
        if ($violations !== []) {
            if (
                $facultyId === null
                || ! $request->boolean(FacultyConflictOverride::REQUEST_FLAG)
                || ! FacultyConflictOverride::onlyOverridable($violations)
            ) {
                return response()->json(
                    FacultyConflictOverride::refusal('The instructor assignment conflicts with an existing schedule.', $violations),
                    422,
                );
            }

            $overriddenIds = array_merge($linkedScheduleIds, FacultyConflictOverride::partnerIds($violations));
        }

        // Assignment continues past the Basic Load into the overload allowance
        // and then pro bono, so this asks rather than refuses — but it asks
        // before the write, so answering No leaves the schedule untouched.
        $activeSemesterId = $this->activeSemesterId();
        if ($faculty !== null) {
            $incoming = array_values(array_filter([$this->loadPairForSchedule($schedule)]));
            $projection = $this->withAssignmentLabel(
                $this->facultyLoad->projectLoad($faculty, $activeSemesterId, $incoming),
                $this->assignmentLabelForSchedule($schedule),
            );

            if (! $request->boolean('confirm_overload')) {
                $confirmation = $this->overloadConfirmationResponse([$projection]);

                if ($confirmation !== null) {
                    return $confirmation;
                }
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
            $overriddenIds,
        ) {
            $before = Schedule::query()->whereIn('id', $linkedScheduleIds)->get();
            // A bulk update fires no model events, so the Schedule hook cannot clear
            // a stale override here. Only meetings whose instructor actually changes
            // lose it: re-saving the same instructor keeps the override standing.
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->where(fn ($query) => $facultyId === null
                    ? $query->whereNotNull('faculty_id')
                    : $query->whereNull('faculty_id')->orWhere('faculty_id', '!=', $facultyId))
                ->update(['faculty_conflict_override' => false]);
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->update(['faculty_id' => $facultyId]);
            FacultyConflictOverride::flag($overriddenIds);

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

        if ($faculty !== null && $request->user()) {
            $this->notifications->notifyInstructorAssignmentProgress($updatedSchedules->first(), $request->user());
        }

        // Projected with nothing incoming, so it reports what the instructor
        // carries now that the assignment is committed.
        $load = $faculty === null
            ? null
            : $this->facultyLoad->projectLoad($faculty->refresh(), $activeSemesterId, []);

        return response()->json([
            'schedule' => $updatedSchedules->first(),
            'schedules' => $updatedSchedules,
            // Past the allowances is pro bono rather than a breach, so there is
            // nothing left to warn about after the save.
            'warnings' => [],
            'load' => $load,
        ]);
    }

    public function clearSection(Request $request, Sections $section): JsonResponse
    {
        return $this->clearSectionInstructors($request, collect([$section]));
    }

    /**
     * POST /api/instructor-assignments/clear
     *
     * The department-wide "clear all instructors". Clearing one section at a
     * time left every other section's assignments -- and so each instructor's
     * load -- in place, which read as a clear that had not worked.
     */
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

        $updatedSchedules = DB::transaction(function () use (
            $request,
            $targetSchedules,
            $scheduleIds,
            $previousFacultyIds,
            $facultyIds,
            $departmentId,
            $activeSemesterId,
            $section,
            $sectionIds,
        ) {
            $before = Schedule::query()->whereIn('id', $scheduleIds)->get();
            Schedule::query()->whereIn('id', $scheduleIds)->update(['faculty_id' => null, 'faculty_conflict_override' => false]);
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
                    'courses_cleared' => $targetSchedules->pluck('course_id')->unique()->count(),
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
        $this->facultyLoad->decorateMany($affectedFaculties, $activeSemesterId);
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => $section !== null
                ? 'All eligible instructor assignments for the section were cleared.'
                : 'All eligible instructor assignments for the selected sections were cleared.',
            'section_id' => $section?->id,
            'section_ids' => $sectionIds,
            'schedules_updated' => $updatedSchedules->count(),
            'courses_cleared' => $targetSchedules->pluck('course_id')->unique()->count(),
            'schedules' => $updatedSchedules,
            'faculties' => $affectedFaculties,
        ]);
    }

    /**
     * Every meeting block of the same course in the same section, so assigning an
     * instructor to one block assigns the whole class.
     *
     * `schedules` has no `subject_id` column — the name is a legacy alias for
     * `course_id` elsewhere in the codebase — so matching on it silently selected
     * nothing and the assignment then failed on an empty collection.
     */
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
        if ($schedule->course === null) {
            return false;
        }

        $teachingDepartmentId = SchedulingPolicy::isMajorCourse($schedule->course)
            ? SchedulingPolicy::majorTeachingDepartmentId($schedule->course, (int) $schedule->department_id)
            : SchedulingPolicy::assignedTeachingDepartmentId($schedule->course) ?? (int) $schedule->department_id;
        if ((int) $teachingDepartmentId !== $departmentId) {
            return false;
        }

        if ($request->user()?->role !== 'program_head') {
            return true;
        }

        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($schedule->course);

        return $requiredProgramId !== null
            && $requiredProgramId === (int) ($request->user()?->program_id ?? 0);
    }
}
