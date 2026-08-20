<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsFacultyOverload;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Terms;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\SchedulingPolicy;
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
        private readonly \App\Services\FacultyLoadService $facultyLoad,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $departmentId = (int) ($request->user()?->department_id ?? 0);
        if ($departmentId === 0) {
            return response()->json(['message' => 'Your account must belong to a department.'], 422);
        }

        $cacheKey = ApiCache::key('instructor_assignments.index', [
            'department_id' => $departmentId,
        ]);

        $data = Cache::remember($cacheKey, ApiCache::LOOKUP_TTL_SECONDS, function () use ($departmentId) {
            $activeTerm = Terms::query()->where('is_active', true)->first();

            if (!$activeTerm) {
                return [
                    'active_term' => null,
                    'current_department_id' => $departmentId,
                    'departments' => [],
                    'subjects' => [],
                    'faculties' => [],
                    'schedules' => [],
                ];
            }

            $assignedCourseIds = SchedulingPolicy::courseIdsAssignedToTeachingDepartment($departmentId);
            $allAssignedCourseIds = SchedulingPolicy::assignedCourseIds();

            $schedules = Schedule::query()
                ->with(['section', 'course.department', 'course.program', 'faculty', 'room', 'department'])
                ->where('term_id', $activeTerm->id)
                ->whereIn('status', self::VISIBLE_STATUSES)
                ->whereHas('course', fn ($query) => $query->where('status', 'active'))
                ->where(function ($scheduleQuery) use ($departmentId, $assignedCourseIds, $allAssignedCourseIds) {
                    if ($assignedCourseIds !== []) {
                        $scheduleQuery->whereIn('course_id', $assignedCourseIds);
                    }

                    $scheduleQuery->orWhere(function ($fallbackQuery) use ($departmentId, $allAssignedCourseIds) {
                        $fallbackQuery->where('department_id', $departmentId);
                        if ($allAssignedCourseIds !== []) {
                            $fallbackQuery->whereNotIn('course_id', $allAssignedCourseIds);
                        }
                    });
                })
                ->orderBy('department_id')
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();

             $faculties = Faculty::query()
                ->with(['department', 'program', 'availabilities'])
                ->where('department_id', $departmentId)
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
            ];
        });

        return response()->json($data);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'faculty_id' => 'required|integer|exists:faculties,id',
        ]);

        $departmentId = (int) ($request->user()?->department_id ?? 0);
        // For a major the offering department is the only one that can assign; the
        // VPAA-assigned teaching department applies to service and minor courses.
        $teachingDepartmentId = $schedule->course
            ? (SchedulingPolicy::isMajorCourse($schedule->course)
                ? SchedulingPolicy::majorTeachingDepartmentId($schedule->course, (int) $schedule->department_id)
                : SchedulingPolicy::assignedTeachingDepartmentId($schedule->course) ?? (int) $schedule->department_id)
            : null;

        if (!$schedule->course) {
            return response()->json([
                'message' => 'Only the VPAA-assigned teaching department can assign this course instructor.',
            ], 403);
        }

        $isMajor = SchedulingPolicy::isMajorCourse($schedule->course);

        if ((int) $teachingDepartmentId !== $departmentId) {
            return response()->json([
                'message' => $isMajor
                    ? 'Only the department that offers this major can assign its instructor.'
                    : 'Only the VPAA-assigned teaching department can assign this course instructor.',
            ], 403);
        }

        if (!in_array($schedule->status, self::ASSIGNABLE_STATUSES, true)) {
            return response()->json([
                'message' => $schedule->status === 'finalized'
                    ? 'A finalized schedule cannot be reassigned.'
                    : 'Instructor assignment is available only after VPAA approval.',
            ], 422);
        }

        $faculty = Faculty::query()->findOrFail($validated['faculty_id']);
        if ((int) $faculty->department_id !== $departmentId || $faculty->status !== 'active') {
            return response()->json([
                'message' => 'The selected instructor must be active and belong to the VPAA-assigned teaching department.',
            ], 422);
        }

        // Checked here as well as in the rule engine so the workspace can say why
        // the instructor is ineligible instead of reporting a generic conflict.
        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($schedule->course);
        if ($requiredProgramId !== null && (int) $faculty->program_id !== $requiredProgramId) {
            $schedule->course->loadMissing('program');
            $programLabel = $schedule->course->program?->code
                ?? $schedule->course->program?->name;

            return response()->json([
                'message' => $programLabel !== null
                    ? "This major belongs to the {$programLabel} program, so only instructors of that program can be assigned."
                    : 'This major belongs to a program the selected instructor is not assigned to.',
            ], 422);
        }

        $linkedSchedules = $this->linkedMeetingBlocks($schedule);
        $linkedScheduleIds = $linkedSchedules->pluck('id')->all();
        $violations = [];

        foreach ($linkedSchedules as $linkedSchedule) {
            $attempt = array_merge($linkedSchedule->toArray(), [
                'faculty_id' => $faculty->id,
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
        $incoming = array_values(array_filter([$this->loadPairForSchedule($schedule)]));

        if (! $request->boolean('confirm_overload')) {
            $confirmation = $this->overloadConfirmationResponse([
                $this->withAssignmentLabel(
                    $this->facultyLoad->projectLoad($faculty, $activeTermId, $incoming),
                    $this->assignmentLabelForSchedule($schedule),
                ),
            ]);

            if ($confirmation !== null) {
                return $confirmation;
            }
        }

        $previousFacultyId = $schedule->faculty_id;
        $updatedSchedules = DB::transaction(function () use (
            $request,
            $linkedSchedules,
            $linkedScheduleIds,
            $faculty,
            $previousFacultyId,
            $departmentId,
        ) {
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->update([
                    'faculty_id' => $faculty->id,
                    'status' => 'faculty_assignment',
                ]);

            SchedulingAuditLog::create([
                'user_id' => $request->user()?->id,
                'term_id' => $linkedSchedules->first()->term_id,
                'section_id' => $linkedSchedules->first()->section_id,
                'department_id' => $departmentId,
                'action' => 'instructor_assigned',
                'metadata' => [
                    'schedule_id' => $linkedSchedules->first()->id,
                    'schedule_ids' => $linkedScheduleIds,
                    'course_id' => $linkedSchedules->first()->course_id,
                    'previous_faculty_id' => $previousFacultyId,
                    'faculty_id' => $faculty->id,
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

        ApiCache::forgetGroup('instructor_assignments.index');

        if ($request->user()) {
            $this->notifications->notifyInstructorAssignmentProgress($updatedSchedules->first(), $request->user());
        }

        // Projected with nothing incoming, so it reports what the instructor
        // carries now that the assignment is committed.
        $load = $this->facultyLoad->projectLoad($faculty->refresh(), $activeTermId, []);

        return response()->json([
            'schedule' => $updatedSchedules->first(),
            'schedules' => $updatedSchedules,
            'warnings' => $this->loadWarnings($load),
            'load' => $load,
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
        return Schedule::query()
            ->where('term_id', $schedule->term_id)
            ->where('section_id', $schedule->section_id)
            ->where('course_id', $schedule->course_id)
            ->where('department_id', $schedule->department_id)
            ->where('preferred_pattern', $schedule->preferred_pattern)
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->get();
    }
}
