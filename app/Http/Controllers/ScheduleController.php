<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Course;
use App\Models\Rooms;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Services\TimeslotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    protected RuleEngine $ruleEngine;

    public function __construct(
        RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
        private readonly TimeslotService $timeslotService,
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
        } elseif (($scope = $this->departmentScope($request)) !== null) {
            $query->where('department_id', $scope);
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
            'room_id'           => 'nullable|exists:rooms,id',
            'department_id'     => 'required|exists:departments,id',
            'day'               => SchedulingPolicy::allowedDaysRule('required'),
            'start_time'        => 'required|date_format:H:i',
            'end_time'          => 'required|date_format:H:i|after:start_time',
            'mode'              => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid'         => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'split_group_id'    => 'nullable|string|max:36',
            'meeting_type'      => 'nullable|in:lecture,laboratory',
            'meeting_index'     => 'nullable|integer|min:1',
            'status'            => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);
        $validated = $this->clearOnlineRoomId($validated);

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
            'operations' => 'required_without:delete_ids|array',
            'operations.*.id' => 'nullable|integer|exists:schedules,id',
            'operations.*.term_id' => 'required_without:operations.*.id|integer|exists:terms,id',
            'operations.*.section_id' => 'required_without:operations.*.id|integer|exists:sections,id',
            'operations.*.course_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.subject_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id' => 'nullable|integer|exists:rooms,id',
            'operations.*.department_id' => 'required_without:operations.*.id|integer|exists:departments,id',
            'operations.*.day' => SchedulingPolicy::allowedDaysRule('required_without:operations.*.id'),
            'operations.*.start_time' => 'required_without:operations.*.id|date_format:H:i',
            'operations.*.end_time' => 'required_without:operations.*.id|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid' => 'sometimes|boolean',
            'operations.*.preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'operations.*.split_group_id' => 'nullable|string|max:36',
            'operations.*.meeting_type' => 'nullable|in:lecture,laboratory',
            'operations.*.meeting_index' => 'nullable|integer|min:1',
            'operations.*.status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer|exists:schedules,id',
        ]);

        $deleteIds = $validated['delete_ids'] ?? [];
        $validated['operations'] = $validated['operations'] ?? [];
        $validated['operations'] = array_map(
            fn (array $operation): array => $this->hydrateExistingScheduleOperation(
                $this->clearOnlineRoomId($operation)
            ),
            $validated['operations']
        );

        $missingCreateFields = [];
        foreach ($validated['operations'] as $index => $operation) {
            if (isset($operation['id'])) {
                continue;
            }

            foreach (['term_id', 'section_id', 'course_id', 'department_id', 'day', 'start_time', 'end_time'] as $field) {
                if (!array_key_exists($field, $operation) || $operation[$field] === null || $operation[$field] === '') {
                    $missingCreateFields[] = "operations.{$index}.{$field}";
                }
            }
        }

        if ($missingCreateFields !== []) {
            return response()->json([
                'message' => 'Schedule operation is missing required fields.',
                'missing_fields' => $missingCreateFields,
            ], 422);
        }

        foreach ($validated['operations'] as $operation) {
            if (
                !isset($operation['id'])
                && isset($operation['department_id'])
                && !$this->payloadBelongsToDepartment($request, (int) $operation['department_id'])
            ) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        $operationIds = collect($validated['operations'])
            ->pluck('id')
            ->filter()
            ->map('intval')
            ->all();

        if (!$this->scheduleIdsBelongToDepartment($request, $operationIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        if (!empty($deleteIds)) {
            if (!$this->scheduleIdsBelongToDepartment($request, $deleteIds)) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        $allViolations = $this->checkIntraBatchConflicts($validated['operations']);

        $mergedIgnoreIds = array_values(array_unique(array_merge($operationIds, array_map('intval', $deleteIds))));

        $orderedOperations = $this->prioritizeSplitAnchorMeetings($validated['operations']);

        foreach ($orderedOperations as $orderedOperation) {
            $index = (int) $orderedOperation['index'];
            $op = $orderedOperation['operation'];
            $attemptData = $op;
            if (isset($attemptData['subject_id']) && !isset($attemptData['course_id'])) {
                $attemptData['course_id'] = $attemptData['subject_id'];
            }

            $attemptData['ignore_schedule_id'] = $mergedIgnoreIds;

            $violations = $this->ruleEngine->validate($attemptData);
            if (!empty($violations)) {
                foreach ($violations as $violation) {
                    $allViolations[] = array_merge($violation, [
                        'operation_index' => $index,
                    ]);
                }
            }
        }

        if (!empty($allViolations)) {
            return response()->json([
                'message' => 'Schedule operation conflicts with existing entries or intra-batch schedules.',
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
            'operations.*.id'                     => 'nullable|integer|exists:schedules,id',
            'operations.*.term_id'                => 'required|integer|exists:terms,id',
            'operations.*.section_id'             => 'required|integer|exists:sections,id',
            'operations.*.course_id'              => 'sometimes|integer|exists:courses,id',
            'operations.*.subject_id'             => 'sometimes|integer|exists:courses,id',
            'operations.*.faculty_id'             => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id'                => 'nullable|integer|exists:rooms,id',
            'operations.*.department_id'          => 'required|integer|exists:departments,id',
            'operations.*.day'                    => SchedulingPolicy::allowedDaysRule('required'),
            'operations.*.start_time'             => 'required|date_format:H:i',
            'operations.*.end_time'               => 'required|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode'                   => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid'              => 'sometimes|boolean',
            'operations.*.preferred_pattern'      => ['nullable', 'string', 'max:20'],
            'operations.*.split_group_id'         => 'nullable|string|max:36',
            'operations.*.meeting_type'           => 'nullable|in:lecture,laboratory',
            'operations.*.meeting_index'          => 'nullable|integer|min:1',
            'operations.*.status'                 => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids'                          => 'sometimes|array',
            'delete_ids.*'                        => 'integer|exists:schedules,id',
        ]);

        $deleteIds     = $validated['delete_ids'] ?? [];
        $validated['operations'] = array_map(
            fn (array $operation): array => $this->hydrateExistingScheduleOperation(
                $this->clearOnlineRoomId($operation)
            ),
            $validated['operations']
        );
        $resolvedOps   = [];
        $resolvedOpsByOriginalIndex = [];
        $allViolations = [];

        foreach ($validated['operations'] as $operation) {
            if (
                !isset($operation['id'])
                && isset($operation['department_id'])
                && !$this->payloadBelongsToDepartment($request, (int) $operation['department_id'])
            ) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        $operationIds = collect($validated['operations'])
            ->pluck('id')
            ->filter()
            ->map('intval')
            ->all();

        if (!$this->scheduleIdsBelongToDepartment($request, $operationIds)
            || !$this->scheduleIdsBelongToDepartment($request, $deleteIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $mergedIgnoreIds = array_values(array_unique(array_merge($operationIds, array_map('intval', $deleteIds))));

        $orderedOperations = $this->prioritizeSplitAnchorMeetings($validated['operations']);

        foreach ($orderedOperations as $orderedOperation) {
            $index = (int) $orderedOperation['index'];
            $op = $orderedOperation['operation'];
            if (isset($op['subject_id']) && !isset($op['course_id'])) {
                $op['course_id'] = $op['subject_id'];
            }

            $op['ignore_schedule_id'] = $mergedIgnoreIds;

            $violations = $this->validateCandidate($op, $resolvedOps);

            if (empty($violations)) {
                // No conflict — keep the original timing.
                unset($op['ignore_schedule_id']);
                $resolvedOps[] = $op;
                $resolvedOpsByOriginalIndex[$index] = $op;
                continue;
            }

            // Determine whether the violation is a time-based conflict that a
            // slot-shift can fix (section, room, or faculty conflict).
            $timeConflictRules = ['section_conflict', 'room_conflict', 'faculty_conflict', 'split_group_day_separation'];
            $hasTimeConflict   = collect($violations)->contains(
                fn ($v) => in_array($v['rule'] ?? '', $timeConflictRules, true)
            );

            if (!$hasTimeConflict) {
                // Non-time violations — check if it's a room type mismatch that
                // can be fixed by swapping to a compatible room automatically.
                $hasRoomAssignmentIssue = collect($violations)->contains(
                    fn ($v) => in_array(($v['rule'] ?? ''), ['room_type_match', 'delivery_room_alignment'], true)
                );

                if ($hasRoomAssignmentIssue) {
                    $swappedOp = $this->attemptRoomSwapResolution($op, $resolvedOps);
                    if ($swappedOp !== null) {
                        unset($swappedOp['ignore_schedule_id']);
                        $resolvedOps[] = $swappedOp;
                        $resolvedOpsByOriginalIndex[$index] = $swappedOp;
                        continue;
                    }
                }

                // Truly unresolvable — surface the violation.
                $courseCode = Course::find($op['course_id'] ?? 0)?->course_code ?? 'Course';
                foreach ($violations as $v) {
                    $allViolations[] = array_merge($v, [
                        'operation_index' => $index,
                        'course_code'     => $courseCode,
                        'day'             => $op['day'],
                        'start_time'      => $op['start_time'],
                        'end_time'        => $op['end_time'],
                    ]);
                }
                continue;
            }

            // --- Resilient Slot-shift & Pattern-day Resolution ---
            $resolvedOp = $this->attemptSlotShiftResolution(
                $op,
                $resolvedOps
            );

            if ($resolvedOp !== null) {
                unset($resolvedOp['ignore_schedule_id']);
                $resolvedOps[] = $resolvedOp;
                $resolvedOpsByOriginalIndex[$index] = $resolvedOp;
            } else {
                // Could not find a valid slot within operating hours.
                $courseCode = Course::find($op['course_id'] ?? 0)?->course_code ?? 'Course';
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
            'operations' => $this->restoreOriginalOperationOrder($resolvedOpsByOriginalIndex),
        ]);
    }

    private function prioritizeSplitAnchorMeetings(array $operations): array
    {
        return collect($operations)
            ->map(fn (array $operation, int $index): array => [
                'index' => $index,
                'operation' => $operation,
                'priority' => !empty($operation['split_group_id']) && (int) ($operation['meeting_index'] ?? 1) === 1 ? 0 : 1,
            ])
            ->sortBy([
                ['priority', 'asc'],
                ['index', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function restoreOriginalOperationOrder(array $operationsByOriginalIndex): array
    {
        ksort($operationsByOriginalIndex);

        return array_values($operationsByOriginalIndex);
    }

    private function validateCandidate(array $op, array $resolvedOps): array
    {
        $dbViolations = $this->ruleEngine->validate($op);
        $intraViolations = $this->checkIntraBatchConflicts(array_merge($resolvedOps, [$op]));
        $splitDayViolations = $this->checkSplitGroupDayConflicts($op, $resolvedOps);

        return array_merge($dbViolations, $intraViolations, $splitDayViolations);
    }

    private function checkSplitGroupDayConflicts(array $op, array $resolvedOps): array
    {
        $splitGroupId = (string) ($op['split_group_id'] ?? '');
        if ($splitGroupId === '') {
            return [];
        }

        $day = (string) ($op['day'] ?? '');
        foreach ($resolvedOps as $resolvedOp) {
            if (
                (string) ($resolvedOp['split_group_id'] ?? '') === $splitGroupId
                && (string) ($resolvedOp['day'] ?? '') === $day
            ) {
                return [[
                    'rule' => 'split_group_day_separation',
                    'message' => 'Split meetings for the same course must be scheduled on different days.',
                ]];
            }
        }

        return [];
    }

    private function testAndResolveCandidate(array $candidate, array $resolvedOps): ?array
    {
        $violations = $this->validateCandidate($candidate, $resolvedOps);
        if (empty($violations)) {
            return $candidate;
        }

        $hasRoomAssignmentIssue = collect($violations)->contains(
            fn ($v) => in_array(($v['rule'] ?? ''), ['room_type_match', 'delivery_room_alignment'], true)
        );

        if ($hasRoomAssignmentIssue) {
            $swapped = $this->attemptRoomSwapResolution($candidate, $resolvedOps);
            if ($swapped !== null) {
                return $swapped;
            }
        }

        return null;
    }

    private function attemptSlotShiftResolution(
        array $op,
        array $resolvedOps
    ): ?array {
        $origStartMins = $this->timeToMinutesLocal($op['start_time']);
        $origEndMins   = $this->timeToMinutesLocal($op['end_time']);
        $durationMins  = $origEndMins - $origStartMins;
        $candidateStartMinutes = $this->generatedStartMinutesByCloseness($durationMins, $origStartMins);

        foreach ($candidateStartMinutes as $newStartMins) {
            if ($newStartMins === $origStartMins) {
                continue;
            }

            $res = $this->testAndResolveCandidate(
                $this->withTime($op, $newStartMins, $durationMins),
                $resolvedOps
            );
            if ($res !== null) {
                return $res;
            }
        }

        // Pattern-day swap search: try alternate days (MW: Mon <-> Wed, TTh: Tue <-> Thu, etc.)
        $daySwaps = [
            'Monday'    => ['Wednesday', 'Friday', 'Tuesday', 'Thursday', 'Saturday'],
            'Tuesday'   => ['Thursday', 'Wednesday', 'Monday', 'Friday', 'Saturday'],
            'Wednesday' => ['Monday', 'Friday', 'Thursday', 'Tuesday', 'Saturday'],
            'Thursday'  => ['Tuesday', 'Friday', 'Wednesday', 'Monday', 'Saturday'],
            'Friday'    => ['Wednesday', 'Monday', 'Thursday', 'Tuesday', 'Saturday'],
            'Saturday'  => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'Sunday'    => ['Saturday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ];

        $alternateDays = $daySwaps[$op['day']] ?? [];
        foreach ($alternateDays as $altDay) {
            // Try original start time on alternate day
            $candidate        = $op;
            $candidate['day'] = $altDay;
            $res = $this->testAndResolveCandidate($candidate, $resolvedOps);
            if ($res !== null) {
                return $res;
            }

            foreach ($candidateStartMinutes as $newStartMins) {
                $res = $this->testAndResolveCandidate(
                    $this->withTime($candidate, $newStartMins, $durationMins),
                    $resolvedOps
                );
                if ($res !== null) {
                    return $res;
                }
            }
        }

        return null;
    }

    private function withTime(array $op, int $startMinutes, int $durationMinutes): array
    {
        $op['start_time'] = $this->minutesToTimeString($startMinutes);
        $op['end_time'] = $this->minutesToTimeString($startMinutes + $durationMinutes);

        return $op;
    }

    private function generatedStartMinutesByCloseness(int $durationMinutes, int $originalStartMinutes): array
    {
        $minutes = array_map(
            fn (string $time): int => $this->timeToMinutesLocal($time),
            $this->timeslotService->generateStartTimes($durationMinutes)
        );

        usort(
            $minutes,
            static fn (int $left, int $right): int =>
                abs($left - $originalStartMinutes) <=> abs($right - $originalStartMinutes)
        );

        return array_values(array_unique($minutes));
    }

    /**
     * When a split operation has a room-type mismatch (e.g. a lecture course
     * landed in a laboratory room), try to find the nearest compatible room
     * for the same department and time slot. Returns the corrected operation
     * array if a conflict-free compatible room exists, or null if none found.
     */
    private function attemptRoomSwapResolution(array $op, array $resolvedOps = []): ?array
    {
        $courseId    = (int) ($op['course_id'] ?? 0);
        $meetingType = $op['meeting_type'] ?? null;
        $mode        = $op['mode'] ?? 'on-site';
        $deptId      = (int) ($op['department_id'] ?? 0);

        // Determine the required room type for this operation.
        $requiredRoomType = match (true) {
            $mode === 'online' => 'online',
            $mode === 'field'  => 'field',
            $meetingType === 'lecture'    => 'lecture',
            $meetingType === 'laboratory' => 'laboratory',
            $courseId > 0 => Course::find($courseId)?->room_type_required ?? 'lecture',
            default       => 'lecture',
        };

        // Fetch all available rooms of the required type visible to the department.
        $candidateRooms = Rooms::query()
            ->where('status', 'available')
            ->where('room_type', $requiredRoomType)
            ->where(static function ($q) use ($deptId): void {
                $q->whereNull('department_id')
                  ->orWhere('department_id', $deptId);
            })
            ->orderBy('room_code')
            ->get();

        // Laboratory courses may also fall back to a lecture room (soft rule).
        if ($requiredRoomType === 'laboratory') {
            $lectureRooms = Rooms::query()
                ->where('status', 'available')
                ->where('room_type', 'lecture')
                ->where(static function ($q) use ($deptId): void {
                    $q->whereNull('department_id')
                      ->orWhere('department_id', $deptId);
                })
                ->orderBy('room_code')
                ->get();
            $candidateRooms = $candidateRooms->merge($lectureRooms);
        }

        foreach ($candidateRooms as $room) {
            // Skip the original room — we already know it fails.
            if ((int) $room->id === (int) ($op['room_id'] ?? 0)) {
                continue;
            }

            $candidate            = $op;
            $candidate['room_id'] = (int) $room->id;

            if (empty($this->validateCandidate($candidate, $resolvedOps))) {
                return $candidate;
            }
        }

        return null;
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

    private function checkIntraBatchConflicts(array $operations): array
    {
        $violations = [];
        $count = count($operations);
        if ($count < 2) {
            return $violations;
        }

        $courseIds = array_values(array_unique(array_filter(array_map(
            fn ($op) => (int) ($op['course_id'] ?? $op['subject_id'] ?? 0),
            $operations
        ))));

        $coursesMap = !empty($courseIds)
            ? Course::whereIn('id', $courseIds)->get()->keyBy('id')
            : collect();
        $roomIds = array_values(array_unique(array_filter(array_map(
            fn ($op) => (int) ($op['room_id'] ?? 0),
            $operations,
        ))));
        $roomCapacityMap = !empty($roomIds)
            ? Rooms::whereIn('id', $roomIds)
                ->get(['id', 'room_type', 'max_concurrent_classes'])
                ->mapWithKeys(fn (Rooms $room): array => [
                    (int) $room->id => max(1, (int) ($room->max_concurrent_classes ?? 1)),
                ])
            : collect();

        for ($i = 0; $i < $count; $i++) {
            $op1 = $operations[$i];
            $termId1 = (int) ($op1['term_id'] ?? 0);
            $sectionId1 = (int) ($op1['section_id'] ?? 0);
            $roomId1 = (int) ($op1['room_id'] ?? 0);
            $facultyId1 = !empty($op1['faculty_id']) ? (int) $op1['faculty_id'] : null;
            $day1 = (string) ($op1['day'] ?? '');
            $startMins1 = $this->timeToMinutesLocal((string) ($op1['start_time'] ?? '00:00'));
            $endMins1 = $this->timeToMinutesLocal((string) ($op1['end_time'] ?? '00:00'));
            $mode1 = (string) ($op1['mode'] ?? 'on-site');
            $courseId1 = (int) ($op1['course_id'] ?? $op1['subject_id'] ?? 0);
            $courseCode1 = $coursesMap->get($courseId1)?->course_code ?? 'Course';

            for ($j = $i + 1; $j < $count; $j++) {
                $op2 = $operations[$j];
                $termId2 = (int) ($op2['term_id'] ?? 0);
                $sectionId2 = (int) ($op2['section_id'] ?? 0);
                $roomId2 = (int) ($op2['room_id'] ?? 0);
                $facultyId2 = !empty($op2['faculty_id']) ? (int) $op2['faculty_id'] : null;
                $day2 = (string) ($op2['day'] ?? '');
                $startMins2 = $this->timeToMinutesLocal((string) ($op2['start_time'] ?? '00:00'));
                $endMins2 = $this->timeToMinutesLocal((string) ($op2['end_time'] ?? '00:00'));
                $mode2 = (string) ($op2['mode'] ?? 'on-site');
                $courseId2 = (int) ($op2['course_id'] ?? $op2['subject_id'] ?? 0);
                $courseCode2 = $coursesMap->get($courseId2)?->course_code ?? 'Course';

                if ($termId1 === $termId2 && $day1 === $day2) {
                    if ($startMins1 < $endMins2 && $startMins2 < $endMins1) {
                        $overlapStart = $this->minutesToTimeString(max($startMins1, $startMins2));
                        $overlapEnd = $this->minutesToTimeString(min($endMins1, $endMins2));

                        if ($sectionId1 === $sectionId2) {
                            $violations[] = [
                                'rule' => 'section_conflict',
                                'operation_index' => $j,
                                'course_code' => $courseCode2,
                                'day' => $day2,
                                'message' => "Intra-batch Section Conflict: {$courseCode1} and {$courseCode2} overlap for section on {$day1} from {$overlapStart} to {$overlapEnd}.",
                            ];
                        }

                        $roomCapacity = (int) ($roomCapacityMap->get($roomId1) ?? 1);
                        if ($roomId1 === $roomId2 && $mode1 !== 'online' && $mode2 !== 'online' && $roomCapacity <= 1) {
                            $violations[] = [
                                'rule' => 'room_conflict',
                                'operation_index' => $j,
                                'course_code' => $courseCode2,
                                'day' => $day2,
                                'message' => "Intra-batch Room Conflict: Room is assigned to both {$courseCode1} and {$courseCode2} at overlapping time {$overlapStart}-{$overlapEnd} on {$day1}.",
                            ];
                        }

                        if ($facultyId1 !== null && $facultyId1 === $facultyId2) {
                            $violations[] = [
                                'rule' => 'faculty_conflict',
                                'operation_index' => $j,
                                'course_code' => $courseCode2,
                                'day' => $day2,
                                'message' => "Intra-batch Faculty Conflict: Instructor is assigned to teach both {$courseCode1} and {$courseCode2} at overlapping time {$overlapStart}-{$overlapEnd} on {$day1}.",
                            ];
                        }
                    }
                }
            }
        }

        return $violations;
    }

    private function hydrateExistingScheduleOperation(array $operation): array
    {
        if (!isset($operation['id'])) {
            return $operation;
        }

        $schedule = Schedule::query()->find((int) $operation['id']);
        if ($schedule === null) {
            return $operation;
        }

        $persisted = [
            'id' => $schedule->id,
            'term_id' => $schedule->term_id,
            'section_id' => $schedule->section_id,
            'course_id' => $schedule->course_id,
            'faculty_id' => $schedule->faculty_id,
            'room_id' => $schedule->room_id,
            'department_id' => $schedule->department_id,
            'day' => $schedule->day,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'mode' => $schedule->mode,
            'is_hybrid' => $schedule->is_hybrid,
            'preferred_pattern' => $schedule->preferred_pattern,
            'split_group_id' => $schedule->split_group_id,
            'meeting_type' => $schedule->meeting_type,
            'meeting_index' => $schedule->meeting_index,
            'status' => $schedule->status,
        ];

        return array_merge($persisted, $operation);
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
            'room_id'           => 'sometimes|nullable|exists:rooms,id',
            'department_id'     => 'sometimes|required|exists:departments,id',
            'day'               => SchedulingPolicy::allowedDaysRule('sometimes'),
            'start_time'        => 'sometimes|required|date_format:H:i',
            'end_time'          => 'sometimes|required|date_format:H:i|after:start_time',
            'mode'              => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid'         => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'split_group_id'    => 'nullable|string|max:36',
            'meeting_type'      => 'nullable|in:lecture,laboratory',
            'meeting_index'     => 'nullable|integer|min:1',
            'status'            => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);
        $validated = $this->clearOnlineRoomId($validated);

        if (!$this->scheduleBelongsToDepartment($request, $schedule)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        if (
            isset($validated['department_id'])
            && !$this->payloadBelongsToDepartment($request, (int) $validated['department_id'])
        ) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

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
        if (!$this->scheduleBelongsToDepartment($request, $schedule)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department', 'split']);
        $deletedSchedule = clone $schedule;

        $splitGroupId = $schedule->split_group_id;

        if ($request->query('delete_group') === 'true' && $splitGroupId) {
            $schedules = Schedule::whereHas('split', function ($q) use ($splitGroupId) {
                $q->where('split_group_id', $splitGroupId);
            })->get();

            if (!$this->scheduleIdsBelongToDepartment($request, $schedules->pluck('id')->all())) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }

            foreach ($schedules as $s) {
                $s->delete();
            }
        } else {
            $schedule->delete();
        }

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

    private function clearOnlineRoomId(array $payload): array
    {
        if (($payload['mode'] ?? null) === 'online') {
            $payload['room_id'] = null;
        }

        return $payload;
    }

    private function payloadBelongsToDepartment(Request $request, int $targetDeptId): bool
    {
        $scope = $this->departmentScope($request);
        return $scope === null || $scope === $targetDeptId;
    }

    private function scheduleBelongsToDepartment(Request $request, Schedule $schedule): bool
    {
        return $this->payloadBelongsToDepartment($request, (int) $schedule->department_id);
    }

    private function scheduleIdsBelongToDepartment(Request $request, array $scheduleIds): bool
    {
        $scope = $this->departmentScope($request);
        if ($scope === null || $scheduleIds === []) {
            return true;
        }

        return !Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->where('department_id', '!=', $scope)
            ->exists();
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

    public function batchStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:schedules,id',
            'status' => SchedulingPolicy::allowedScheduleStatusesRule('required'),
        ]);

        if (!$this->scheduleIdsBelongToDepartment($request, $validated['ids'])) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $updated = Schedule::whereIn('id', $validated['ids'])
            ->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

        $schedules = Schedule::whereIn('id', $validated['ids'])
            ->with(['term', 'section', 'course', 'faculty', 'room', 'department'])
            ->get();

        return response()->json([
            'message' => 'Batch status update completed successfully.',
            'schedules' => $schedules,
            'schedules_updated' => $updated,
        ]);
    }
}
