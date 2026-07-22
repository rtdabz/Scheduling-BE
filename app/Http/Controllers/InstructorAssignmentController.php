<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Terms;
use App\Services\Scheduling\RuleEngine;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InstructorAssignmentController extends Controller
{
    private const ASSIGNABLE_STATUSES = ['approved', 'faculty_assignment'];

    private const VISIBLE_STATUSES = ['approved', 'faculty_assignment', 'finalized'];

    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
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

            $schedules = Schedule::query()
                ->with(['section', 'course.department', 'faculty', 'room', 'department'])
                ->where('term_id', $activeTerm->id)
                ->whereIn('status', self::VISIBLE_STATUSES)
                ->whereHas('course', function ($query) use ($departmentId) {
                    $query->where('department_id', $departmentId)
                        ->where('status', 'active');
                })
                ->orderBy('department_id')
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();

            $faculties = Faculty::query()
                ->with('department')
                ->where('department_id', $departmentId)
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();

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

        $schedule->loadMissing('course');
        $departmentId = (int) ($request->user()?->department_id ?? 0);

        if (
            !$schedule->course
            || (int) $schedule->course->department_id !== $departmentId
        ) {
            return response()->json([
                'message' => 'Only the department that owns this subject can assign its instructor.',
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
                'message' => 'The selected instructor must be active and belong to the subject-owning department.',
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
                    'subject_id' => $linkedSchedules->first()->subject_id,
                    'previous_faculty_id' => $previousFacultyId,
                    'faculty_id' => $faculty->id,
                    'offering_department_id' => $linkedSchedules->first()->department_id,
                ],
                'created_at' => now(),
            ]);

            return Schedule::query()
                ->with(['section', 'subject.department', 'faculty', 'room', 'department'])
                ->whereIn('id', $linkedScheduleIds)
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();
        });

        ApiCache::forgetGroup('instructor_assignments.index');

        if ($request->user()) {
            $this->notifications->notifyInstructorAssignmentProgress($updatedSchedules->first(), $request->user());
        }

        return response()->json([
            'schedule' => $updatedSchedules->first(),
            'schedules' => $updatedSchedules,
        ]);
    }

    private function linkedMeetingBlocks(Schedule $schedule)
    {
        return Schedule::query()
            ->where('term_id', $schedule->term_id)
            ->where('section_id', $schedule->section_id)
            ->where('subject_id', $schedule->subject_id)
            ->where('department_id', $schedule->department_id)
            ->where('preferred_pattern', $schedule->preferred_pattern)
            ->whereIn('status', self::ASSIGNABLE_STATUSES)
            ->get();
    }
}
