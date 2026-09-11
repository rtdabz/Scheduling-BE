<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsFacultyOverload;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\FacultyLoadService;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Schedule\ManualHybridFacultyAssignmentResolver;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $activeTerm = Terms::query()->where('is_active', true)->first();

            if (! $activeTerm) {
                return [
                    'active_term' => null,
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
                ->where('term_id', $activeTerm->id)
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
            $this->facultyLoad->decorateMany($faculties, (int) $activeTerm->id);

            $courses = $schedules->pluck('course')->filter()->unique('id')->values();

            return [
                'active_term' => $activeTerm,
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
            $violations = array_merge($violations, $this->ruleEngine->validate($attempt));
        }

        if ($violations !== []) {
            return response()->json([
                'message' => 'The instructor assignment conflicts with an existing schedule.',
                'violations' => $violations,
            ], 422);
        }

        // Assignment continues past the Basic Load into the overload allowance
        // and then pro bono, so this asks rather than refuses — but it asks
        // before the write, so answering No leaves the schedule untouched.
        $activeTermId = $this->activeTermId();
        if ($faculty !== null) {
            $incoming = array_values(array_filter([$this->loadPairForSchedule($schedule)]));
            $projection = $this->withAssignmentLabel(
                $this->facultyLoad->projectLoad($faculty, $activeTermId, $incoming),
                $this->assignmentLabelForSchedule($schedule),
            );

            $ceilingError = $this->facultyCeilingExceededResponse([$projection]);
            if ($ceilingError !== null) {
                return $ceilingError;
            }

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
        ) {
            $before = Schedule::query()->whereIn('id', $linkedScheduleIds)->get();
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->update(['faculty_id' => $facultyId]);

            $after = Schedule::query()->whereIn('id', $linkedScheduleIds)->get();
            $action = $facultyId === null ? 'instructor_assignment_released' : 'instructor_assigned';
            $version = $this->historyRecorder->record($action, $before, $after, $request->user()?->id, $linkedSchedules->first()->term_id, $departmentId, 'instructor_assignment');
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'term_id' => $linkedSchedules->first()->term_id,
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
            : $this->facultyLoad->projectLoad($faculty->refresh(), $activeTermId, []);

        return response()->json([
            'schedule' => $updatedSchedules->first(),
            'schedules' => $updatedSchedules,
            'warnings' => $load === null ? [] : $this->loadWarnings($load),
            'load' => $load,
        ]);
    }

    public function clearSection(Request $request, Sections $section): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        $activeTermId = $this->activeTermId();
        if ($activeTermId === null || (int) $section->term_id !== $activeTermId) {
            return response()->json(['message' => 'Instructor assignments can only be cleared for the active term.'], 422);
        }

        $sectionSchedules = Schedule::query()
            ->with('course')
            ->where('term_id', $activeTermId)
            ->where('section_id', $section->id)
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->where('faculty_assignment_done', false)
            ->whereNotNull('faculty_id')
            ->get();
        $targetSchedules = $sectionSchedules
            ->filter(fn (Schedule $schedule): bool => $this->userCanManageInstructor($request, $schedule, $departmentId))
            ->values();

        if ($targetSchedules->isEmpty()) {
            return response()->json([
                'message' => 'This section has no instructor assignments that your account can clear.',
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
            $activeTermId,
            $section,
        ) {
            $before = Schedule::query()->whereIn('id', $scheduleIds)->get();
            Schedule::query()->whereIn('id', $scheduleIds)->update(['faculty_id' => null]);
            $after = Schedule::query()->whereIn('id', $scheduleIds)->get();
            $version = $this->historyRecorder->record(
                'instructor_assignment_released',
                $before,
                $after,
                $request->user()?->id,
                $activeTermId,
                $departmentId,
                'instructor_assignment',
            );
            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'term_id' => $activeTermId,
                'section_id' => $section->id,
                'department_id' => $departmentId,
                'action' => 'instructor_assignment_released',
                'history_version_id' => $version->id,
                'metadata' => [
                    'reason' => 'section_clear',
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
        $this->facultyLoad->decorateMany($affectedFaculties, $activeTermId);
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => 'All eligible instructor assignments for the section were cleared.',
            'section_id' => $section->id,
            'schedules_updated' => $updatedSchedules->count(),
            'courses_cleared' => $targetSchedules->pluck('course_id')->unique()->count(),
            'schedules' => $updatedSchedules,
            'faculties' => $affectedFaculties,
        ]);
    }

    /**
     * The unit allowances are a soft rule: a chair may still need to overload
     * someone, so a load past the ceiling reports a warning next to the saved
     * schedule rather than refusing it. The overload confirmation already asked
     * before the write — this is the record of where the load landed.
     *
     * @param  array<string, mixed>  $load  a post-write FacultyLoadService::projectLoad() result
     * @return array<int, array<string, mixed>>
     */
    private function loadWarnings(array $load): array
    {
        $ceiling = (int) $load['unit_ceiling'];
        $assigned = (int) $load['projected_units'];

        if ($ceiling <= 0 || $assigned <= $ceiling) {
            return [];
        }

        return [[
            'rule' => 'faculty_unit_ceiling',
            'severity' => 'soft',
            'message' => "{$load['faculty_name']} now carries {$assigned} units, above their {$ceiling}-unit ceiling "
                ."(Basic Load {$load['basic_load']}, plus overload {$load['overload_units']} and pro bono {$load['probono_units']}).",
            'assigned_units' => $assigned,
            'required_units' => (int) $load['basic_load'],
            'unit_ceiling' => $ceiling,
        ]];
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
            ->where('term_id', $schedule->term_id)
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
