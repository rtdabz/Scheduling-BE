<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Course;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\SchedulingPolicy;
use App\Services\SystemNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    protected RuleEngine $ruleEngine;

    public function __construct(
        RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
    )
    {
        $this->ruleEngine = $ruleEngine;
    }

    public function index(Request $request)
    {
        $query = Schedule::with([
            'term', 'section', 'course', 'faculty', 'room', 'department'
        ]);

        if ($request->has('term_id') && $request->term_id) {
            $query->where('term_id', $request->term_id);
        }

        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        } elseif ($this->departmentScope($request) !== null) {
            $query->where('department_id', $this->departmentScope($request));
        }

        $schedules = $query->latest()->get();

        return response()->json($schedules);
    }

    public function pendingDepartmentCount(Request $request): JsonResponse
    {
        $targetStatus = $request->user()?->role === 'vpaa'
            ? 'approved_by_dean'
            : 'submitted';

        $count = Schedule::query()
            ->where('status', $targetStatus)
            ->distinct()
            ->count('department_id');

        return response()->json(['count' => $count]);
    }

    // Create schedule
    public function store(Request $request)
    {
        // Support course_id or subject_id
        if (!$request->has('course_id') && $request->has('subject_id')) {
            $request->merge(['course_id' => $request->input('subject_id')]);
        }

        $validated = $request->validate([
            'term_id'           => 'required|exists:terms,id',
            'section_id'        => 'required|exists:sections,id',
            'course_id'         => 'required|exists:courses,id',
            'faculty_id'        => 'nullable|exists:faculties,id',
            'room_id'           => 'required|exists:rooms,id',
            'department_id'     => 'required|exists:departments,id',
            'day'               => SchedulingPolicy::allowedDaysRule('required'),
            'start_time'        => 'required|date_format:H:i',
            'end_time'          => 'required|date_format:H:i|after:start_time',
            'mode'              => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid'         => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'status'            => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);

        if (!$this->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $violations = $this->ruleEngine->validate($validated);

        if (!empty($violations)) {
            return response()->json([
                'message'    => 'Schedule conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

        $schedule = Schedule::create($validated);
        $schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department']);
        $this->notifyScheduleSaved($request, $schedule, 'created');

        return response()->json($schedule, 201);
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => 'required|array',
            'operations.*.id' => 'nullable|integer|exists:schedules,id',
            'operations.*.term_id' => 'required_without:operations.*.id|integer|exists:terms,id',
            'operations.*.section_id' => 'required_without:operations.*.id|integer|exists:sections,id',
            'operations.*.course_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.subject_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id' => 'required_without:operations.*.id|integer|exists:rooms,id',
            'operations.*.department_id' => 'required_without:operations.*.id|integer|exists:departments,id',
            'operations.*.day' => SchedulingPolicy::allowedDaysRule('required_without:operations.*.id'),
            'operations.*.start_time' => 'required_without:operations.*.id|date_format:H:i',
            'operations.*.end_time' => 'required_without:operations.*.id|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid' => 'sometimes|boolean',
            'operations.*.preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'operations.*.status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer|exists:schedules,id',
        ]);

        $deleteIds = $validated['delete_ids'] ?? [];
        $allViolations = [];

        foreach ($validated['operations'] as $index => $op) {
            $attemptData = $op;
            if (isset($attemptData['subject_id']) && !isset($attemptData['course_id'])) {
                $attemptData['course_id'] = $attemptData['subject_id'];
            }

            // Build the ignore list: own ID (for updates) + all delete_ids so the
            // rule engine skips baseline records that will be removed in this batch.
            $ownIgnoreId = isset($op['id']) ? [(int) $op['id']] : [];
            $mergedIgnoreIds = array_values(array_unique(array_merge($ownIgnoreId, array_map('intval', $deleteIds))));
            $attemptData['ignore_schedule_id'] = $mergedIgnoreIds;

            $violations = $this->ruleEngine->validate($attemptData);
            if (!empty($violations)) {
                $allViolations = array_merge($allViolations, $violations);
            }
        }

        if (!empty($allViolations)) {
            return response()->json([
                'message' => 'Schedule operation conflicts with existing entries.',
                'violations' => $allViolations,
            ], 422);
        }

        $savedSchedules = [];
        $deletedScheduleIds = [];

        DB::transaction(function () use ($validated, $deleteIds, &$savedSchedules, &$deletedScheduleIds) {
            if (!empty($deleteIds)) {
                Schedule::whereIn('id', $deleteIds)->delete();
                $deletedScheduleIds = array_map('intval', $deleteIds);
            }

            foreach ($validated['operations'] as $op) {
                if (isset($op['subject_id']) && !isset($op['course_id'])) {
                    $op['course_id'] = $op['subject_id'];
                }

                if (isset($op['id'])) {
                    $schedule = Schedule::findOrFail($op['id']);
                    $schedule->update($op);
                } else {
                    $schedule = Schedule::create($op);
                }
                $savedSchedules[] = $schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department']);
            }
        });

        return response()->json([
            'message' => 'Batch schedule operation completed successfully.',
            'schedules' => $savedSchedules,
            'deleted_schedule_ids' => $deletedScheduleIds,
        ]);
    }

    /**
     * Validate split-schedule operations against the Rule Engine and attempt
     * automatic conflict resolution via slot-shift search before saving.
     *
     * Returns the (possibly time-adjusted) operations if all sessions are
     * conflict-free, or a 422 with per-session violation details if any
     * session cannot be resolved within operating hours.
     */
    public function validateSplits(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations'                          => 'required|array',
            'operations.*.term_id'                => 'required|integer|exists:terms,id',
            'operations.*.section_id'             => 'required|integer|exists:sections,id',
            'operations.*.course_id'              => 'sometimes|integer|exists:courses,id',
            'operations.*.subject_id'             => 'sometimes|integer|exists:courses,id',
            'operations.*.faculty_id'             => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id'                => 'required|integer|exists:rooms,id',
            'operations.*.department_id'          => 'required|integer|exists:departments,id',
            'operations.*.day'                    => SchedulingPolicy::allowedDaysRule('required'),
            'operations.*.start_time'             => 'required|date_format:H:i',
            'operations.*.end_time'               => 'required|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode'                   => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid'              => 'sometimes|boolean',
            'operations.*.preferred_pattern'      => ['nullable', 'string', 'max:20'],
            'operations.*.status'                 => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids'                          => 'sometimes|array',
            'delete_ids.*'                        => 'integer|exists:schedules,id',
        ]);

        $deleteIds     = $validated['delete_ids'] ?? [];
        $resolvedOps   = [];
        $allViolations = [];

        // Operating hours: 07:00 – 21:00 (OPERATING_START_MINUTES = 420)
        $operatingStartMinutes = SchedulingPolicy::OPERATING_START_MINUTES; // 420
        $operatingEndMinutes   = $operatingStartMinutes + (SchedulingPolicy::TOTAL_SLOTS * SchedulingPolicy::SLOT_MINUTES); // 420 + 840 = 1260 = 21:00
        $slotMinutes           = SchedulingPolicy::SLOT_MINUTES; // 30

        foreach ($validated['operations'] as $index => $op) {
            if (isset($op['subject_id']) && !isset($op['course_id'])) {
                $op['course_id'] = $op['subject_id'];
            }

            // Build ignore list: all baseline records being replaced in this batch.
            $mergedIgnoreIds            = array_values(array_unique(array_map('intval', $deleteIds)));
            $op['ignore_schedule_id']   = $mergedIgnoreIds;

            $violations = $this->ruleEngine->validate($op);

            if (empty($violations)) {
                // No conflict — keep the original timing.
                unset($op['ignore_schedule_id']);
                $resolvedOps[] = $op;
                continue;
            }

            // Determine whether the violation is a time-based conflict that a
            // slot-shift can fix (section, room, or faculty conflict).
            $timeConflictRules = ['section_conflict', 'room_conflict', 'faculty_conflict'];
            $hasTimeConflict   = collect($violations)->contains(
                fn ($v) => in_array($v['rule'] ?? '', $timeConflictRules, true)
            );

            if (!$hasTimeConflict) {
                // Non-time violations (e.g. delivery mode, room type) cannot be
                // resolved automatically — surface them directly.
                $courseCode = \App\Models\Course::find($op['course_id'] ?? 0)?->course_code ?? 'Course';
                foreach ($violations as $v) {
                    $allViolations[] = array_merge($v, [
                        'operation_index' => $index,
                        'course_code'     => $courseCode,
                        'day'             => $op['day'],
                        'start_time'      => $op['start_time'],
                        'end_time'        => $op['end_time'],
                    ]);
                }
                $resolvedOps[] = $op; // keep original so index stays aligned
                continue;
            }

            // --- Slot-shift search ---
            // Compute the original duration in minutes so we can keep it constant.
            $origStartMins = $this->timeToMinutesLocal($op['start_time']);
            $origEndMins   = $this->timeToMinutesLocal($op['end_time']);
            $durationMins  = $origEndMins - $origStartMins;

            $resolved       = false;
            $resolvedOp     = null;
            $maxShiftSlots  = 8; // Up to 4 hours of slot shifts (+30 min each)

            for ($shift = 1; $shift <= $maxShiftSlots; $shift++) {
                $newStartMins = $origStartMins + ($shift * $slotMinutes);
                $newEndMins   = $newStartMins + $durationMins;

                // Stop if we would exceed operating hours.
                if ($newEndMins > $operatingEndMinutes) {
                    break;
                }

                $candidate               = $op;
                $candidate['start_time'] = $this->minutesToTimeString($newStartMins);
                $candidate['end_time']   = $this->minutesToTimeString($newEndMins);

                $candidateViolations = $this->ruleEngine->validate($candidate);

                if (empty($candidateViolations)) {
                    $resolved       = true;
                    $resolvedOp     = $candidate;
                    break;
                }
            }

            if ($resolved && $resolvedOp !== null) {
                unset($resolvedOp['ignore_schedule_id']);
                $resolvedOps[] = $resolvedOp;
            } else {
                // Could not find a valid slot within operating hours.
                $courseCode = \App\Models\Course::find($op['course_id'] ?? 0)?->course_code ?? 'Course';
                $allViolations[] = [
                    'rule'            => 'split_unresolvable',
                    'operation_index' => $index,
                    'course_code'     => $courseCode,
                    'day'             => $op['day'],
                    'start_time'      => $op['start_time'],
                    'end_time'        => $op['end_time'],
                    'message'         => "Could not find a conflict-free time slot for {$courseCode} on {$op['day']} "
                        . "starting at {$op['start_time']}. All slots within operating hours are occupied. "
                        . "Please resolve the conflict manually or change the split day.",
                ];
                $resolvedOps[] = $op; // keep original index alignment
            }
        }

        if (!empty($allViolations)) {
            return response()->json([
                'status'       => 'conflict',
                'message'      => 'One or more split sessions could not be scheduled conflict-free.',
                'violations'   => $allViolations,
            ], 422);
        }

        return response()->json([
            'status'     => 'ok',
            'message'    => 'All split sessions validated successfully.',
            'operations' => $resolvedOps,
        ]);
    }

    private function timeToMinutesLocal(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return ($h * 60) + $m;
    }

    private function minutesToTimeString(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    public function show(Schedule $schedule)
    {
        return response()->json($schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department']));
    }

    public function byTerm($termId)
    {
        $schedules = Schedule::with(['term', 'section', 'course', 'faculty', 'room', 'department'])
            ->where('term_id', $termId)
            ->latest()
            ->get();

        return response()->json($schedules);
    }

    public function bySection($sectionId)
    {
        $schedules = Schedule::with(['term', 'section', 'course', 'faculty', 'room', 'department'])
            ->where('section_id', $sectionId)
            ->latest()
            ->get();

        return response()->json($schedules);
    }

    public function update(Request $request, Schedule $schedule)
    {
        if (!$request->has('course_id') && $request->has('subject_id')) {
            $request->merge(['course_id' => $request->input('subject_id')]);
        }

        $validated = $request->validate([
            'term_id'           => 'sometimes|required|exists:terms,id',
            'section_id'        => 'sometimes|required|exists:sections,id',
            'course_id'         => 'sometimes|required|exists:courses,id',
            'faculty_id'        => 'nullable|exists:faculties,id',
            'room_id'           => 'sometimes|required|exists:rooms,id',
            'department_id'     => 'sometimes|required|exists:departments,id',
            'day'               => SchedulingPolicy::allowedDaysRule('sometimes'),
            'start_time'        => 'sometimes|required|date_format:H:i',
            'end_time'          => 'sometimes|required|date_format:H:i|after:start_time',
            'mode'              => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid'         => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'status'            => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);

        $attemptData = array_merge($schedule->toArray(), $validated, ['ignore_schedule_id' => $schedule->id]);

        $violations = $this->ruleEngine->validate($attemptData);

        if (!empty($violations)) {
            return response()->json([
                'message'    => 'Schedule update conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

        $schedule->update($validated);
        $schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department']);
        $this->notifyScheduleSaved($request, $schedule, 'updated');

        return response()->json($schedule);
    }

    public function destroy(Request $request, Schedule $schedule)
    {
        $schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department']);
        $deletedSchedule = clone $schedule;

        $schedule->delete();
        $this->notifyScheduleSaved($request, $deletedSchedule, 'deleted');

        return response()->json(['message' => 'Schedule deleted successfully']);
    }

    private function departmentScope(Request $request): ?int
    {
        $user = $request->user();
        if ($user->isVpaa() || $user->department_id === null) {
            return null;
        }

        return (int) $user->department_id;
    }

    private function payloadBelongsToDepartment(Request $request, int $targetDeptId): bool
    {
        $scope = $this->departmentScope($request);
        return $scope === null || $scope === $targetDeptId;
    }

    private function notifyScheduleSaved(Request $request, Schedule $schedule, string $action): void
    {
        $actor = $request->user();
        if (!$actor) return;

        $courseCode = $schedule->course?->course_code ?? 'Course';
        $sectionName = $schedule->section?->section_name ?? 'Section';

        $this->notifications->notifyRoles(
            ['vpaa', 'dean'],
            'schedule_activity',
            "Schedule " . ucfirst($action),
            "{$actor->name} {$action} schedule for {$courseCode} ({$sectionName}).",
            $actor,
            $schedule->department_id,
            $schedule->term_id
        );
    }
}
