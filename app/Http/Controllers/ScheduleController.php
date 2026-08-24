<?php

namespace App\Http\Controllers;

use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Concerns\ConfirmsFacultyOverload;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Terms;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\BatchConflict;
use App\Services\Scheduling\BatchConflictValidator;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\ScheduleAuthorizationService;
use App\Services\Scheduling\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Services\TimeslotService;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    use ConfirmsFacultyOverload;

    private const REPLACEABLE_BATCH_STATUSES = ['draft', 'completed', 'revision'];
    private const PLOTTING_EDITABLE_STATUSES = ['draft', 'completed', 'revision'];

    /** Seconds to wait for a concurrent batch save on the same term before giving up. */
    private const SCHEDULE_LOCK_TIMEOUT_SECONDS = 10;

    protected RuleEngine $ruleEngine;

    public function __construct(
        RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
        private readonly TimeslotService $timeslotService,
        private readonly BatchConflictValidator $batchConflicts,
        private readonly FacultyLoadService $facultyLoad,
        private readonly ScheduleAuthorizationService $authorization,
    ) {
        $this->ruleEngine = $ruleEngine;
    }

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 500), 1), 1000);
        $query = Schedule::with([
            'term', 'section', 'course', 'faculty', 'room', 'department',
        ]);

        if ($request->has('term_id') && $request->term_id) {
            $query->where('term_id', $request->term_id);
        }

        if ($this->authorization->rejectsRequestedDepartment($request, $request->query('department_id'))) {
            return response()->json(['message' => 'You can only view schedules for your department.'], 403);
        }
        if (($scope = $this->authorization->requestedDepartment($request, $request->query('department_id'))) !== null) {
            $query->where('department_id', $scope);
        }

        $schedules = $query->latest()->limit($perPage)->get();

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
        if (! $request->has('course_id') && $request->has('subject_id')) {
            $request->merge(['course_id' => $request->input('subject_id')]);
        }

        $validated = $request->validate([
            'term_id' => 'required|exists:terms,id',
            'section_id' => 'required|exists:sections,id',
            'course_id' => 'required|exists:courses,id',
            'faculty_id' => 'nullable|exists:faculties,id',
            'room_id' => 'nullable|exists:rooms,id',
            'department_id' => 'required|exists:departments,id',
            'day' => SchedulingPolicy::allowedDaysRule('required'),
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'split_group_id' => 'nullable|string|max:36',
            'meeting_type' => 'nullable|in:lecture,laboratory',
            'meeting_index' => 'nullable|integer|min:1',
            'status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);
        $validated = $this->clearOnlineRoomId($validated);

        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $duplicateMessage = $this->delegatedCourseScheduleMessage($validated);
        if ($duplicateMessage !== null) {
            return response()->json(['message' => $duplicateMessage], 422);
        }

        $violations = $this->ruleEngine->validate($validated);

        if (! empty($violations)) {
            return response()->json([
                'message' => 'Schedule conflicts with existing entries.',
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
            // Existing rows support partial updates; create-only requirements are
            // enforced below after persisted data has been hydrated.
            'operations.*.term_id' => 'sometimes|integer|exists:terms,id',
            'operations.*.section_id' => 'sometimes|integer|exists:sections,id',
            'operations.*.course_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.subject_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id' => 'nullable|integer|exists:rooms,id',
            'operations.*.department_id' => 'sometimes|integer|exists:departments,id',
            'operations.*.day' => SchedulingPolicy::allowedDaysRule('sometimes'),
            'operations.*.start_time' => 'sometimes|date_format:H:i',
            'operations.*.end_time' => 'sometimes|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid' => 'sometimes|boolean',
            'operations.*.preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'operations.*.split_group_id' => 'nullable|string|max:36',
            'operations.*.meeting_type' => 'nullable|in:lecture,laboratory',
            'operations.*.meeting_index' => 'nullable|integer|min:1',
            'operations.*.status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer|exists:schedules,id',
            'replace_section_ids' => 'sometimes|array',
            'replace_section_ids.*' => 'integer|exists:sections,id',
            'replace_term_id' => 'nullable|integer|exists:terms,id',
        ]);

        $deleteIds = $validated['delete_ids'] ?? [];
        $replaceSectionIds = array_values(array_unique(array_map('intval', $validated['replace_section_ids'] ?? [])));
        $replaceTermId = isset($validated['replace_term_id']) ? (int) $validated['replace_term_id'] : null;
        $validated['operations'] = $validated['operations'] ?? [];
        $providedOperationFields = collect($validated['operations'])
            ->filter(static fn (array $operation): bool => isset($operation['id']))
            ->mapWithKeys(static fn (array $operation): array => [
                (int) $operation['id'] => array_keys($operation),
            ])
            ->all();
        $validated['operations'] = array_map(
            fn (array $operation): array => $this->hydrateExistingScheduleOperation(
                $this->clearOnlineRoomId($operation)
            ),
            $validated['operations']
        );

        if ($replaceSectionIds !== []) {
            $operationTermIds = collect($validated['operations'])
                ->pluck('term_id')
                ->filter()
                ->map('intval')
                ->unique()
                ->values()
                ->all();

            if ($replaceTermId === null && count($operationTermIds) === 1) {
                $replaceTermId = (int) $operationTermIds[0];
            }

            if ($replaceTermId === null) {
                return response()->json([
                    'message' => 'Replacement term is required when replacing section schedules.',
                ], 422);
            }

            if (! $this->authorization->sectionIdsBelongToDepartment($request, $replaceSectionIds)) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }

            $replaceScheduleIds = Schedule::query()
                ->where('term_id', $replaceTermId)
                ->whereIn('section_id', $replaceSectionIds)
                ->whereIn('status', self::REPLACEABLE_BATCH_STATUSES)
                ->pluck('id')
                ->map('intval')
                ->all();

            $deleteIds = array_values(array_unique(array_merge(array_map('intval', $deleteIds), $replaceScheduleIds)));
        }

        $missingCreateFields = [];
        foreach ($validated['operations'] as $index => $operation) {
            if (isset($operation['id'])) {
                continue;
            }

            foreach (['term_id', 'section_id', 'course_id', 'department_id', 'day', 'start_time', 'end_time'] as $field) {
                if (! array_key_exists($field, $operation) || $operation[$field] === null || $operation[$field] === '') {
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
                ! isset($operation['id'])
                && isset($operation['department_id'])
                && ! $this->authorization->payloadBelongsToDepartment($request, (int) $operation['department_id'])
            ) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        foreach ($validated['operations'] as $operation) {
            if (! isset($operation['id'])) {
                if (isset($operation['status']) && $operation['status'] !== 'draft') {
                    return response()->json([
                        'message' => 'New timetable entries must start as draft. Use the approval workflow to advance status.',
                    ], 422);
                }
                $duplicateMessage = $this->delegatedCourseScheduleMessage($operation);
                if ($duplicateMessage !== null) {
                    return response()->json([
                        'message' => $duplicateMessage,
                    ], 422);
                }
            }
        }

        $operationIds = collect($validated['operations'])
            ->pluck('id')
            ->filter()
            ->map('intval')
            ->all();

        if (! $this->authorization->scheduleIdsBelongToDepartment($request, $operationIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        // Authorize deletions before validating unrelated operation state. This
        // prevents a mixed batch from leaking a 422 for a foreign delete target
        // instead of returning the required authorization response.
        if (! empty($deleteIds) && ! $this->authorization->scheduleIdsBelongToDepartment($request, $deleteIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $plottingFields = [
            'term_id', 'section_id', 'course_id', 'subject_id', 'room_id', 'department_id',
            'day', 'start_time', 'end_time', 'mode', 'is_hybrid', 'preferred_pattern',
            'split_group_id', 'meeting_type', 'meeting_index', 'status',
        ];
        $existingBatchSchedules = $operationIds === []
            ? collect()
            : Schedule::query()->whereIn('id', $operationIds)->get()->keyBy('id');
        foreach ($validated['operations'] as $operation) {
            if (! isset($operation['id'])) {
                continue;
            }
            $existing = $existingBatchSchedules->get((int) $operation['id']);
            if ($existing === null) {
                continue;
            }
            $providedFields = $providedOperationFields[(int) $operation['id']] ?? array_keys($operation);
            $statusOnlyUpdate = array_key_exists('status', $operation)
                && $operation['status'] !== $existing->status
                && collect($providedFields)
                    ->reject(static fn (string $field): bool => in_array($field, ['id', 'status'], true))
                    ->isEmpty();
            if (array_key_exists('status', $operation) && $operation['status'] !== $existing->status && ! $statusOnlyUpdate) {
                return response()->json([
                    'message' => 'Schedule status must be changed through the approval workflow.',
                ], 422);
            }
            $changesPlotting = collect($plottingFields)->contains(
                static fn (string $field): bool => in_array($field, $providedFields, true)
            );
            if ($changesPlotting && ! in_array($existing->status, self::PLOTTING_EDITABLE_STATUSES, true)) {
                return response()->json([
                    'message' => 'This schedule is locked at its current approval stage. Withdraw or return it to revision before editing the timetable.',
                ], 422);
            }
        }

        if (! empty($deleteIds)) {
            if (! $this->authorization->scheduleIdsBelongToDepartment($request, $deleteIds)) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        $mergedIgnoreIds = array_values(array_unique(array_merge($operationIds, array_map('intval', $deleteIds))));

        $savedSchedules = [];
        $deletedScheduleIds = [];

        // Conflict validation must observe the same snapshot the write commits
        // against, so it runs inside the transaction rather than before it.
        // The advisory lock serializes concurrent batch writes for the same
        // term, which is what actually closes the check-then-write race.
        try {
            $this->withScheduleWriteLock($this->conflictScopeTermIds($validated['operations'], $deleteIds), function () use ($validated, $deleteIds, $mergedIgnoreIds, &$savedSchedules, &$deletedScheduleIds): void {
                DB::transaction(function () use ($validated, $deleteIds, $mergedIgnoreIds, &$savedSchedules, &$deletedScheduleIds): void {
                    $allViolations = $this->checkIntraBatchConflicts($validated['operations'], $mergedIgnoreIds);

                    $orderedOperations = $this->prioritizeSplitAnchorMeetings($validated['operations']);

                    foreach ($orderedOperations as $orderedOperation) {
                        $index = (int) $orderedOperation['index'];
                        $op = $orderedOperation['operation'];
                        $attemptData = $op;
                        if (isset($attemptData['subject_id']) && ! isset($attemptData['course_id'])) {
                            $attemptData['course_id'] = $attemptData['subject_id'];
                        }

                        $attemptData['ignore_schedule_id'] = $mergedIgnoreIds;

                        $violations = $this->ruleEngine->validate($attemptData);
                        if (! empty($violations)) {
                            foreach ($violations as $violation) {
                                $allViolations[] = array_merge($violation, [
                                    'operation_index' => $index,
                                ]);
                            }
                        }
                    }

                    if (! empty($allViolations)) {
                        throw new ScheduleConflictException($allViolations);
                    }

                    if (! empty($deleteIds)) {
                        Schedule::whereIn('id', $deleteIds)->delete();
                        $deletedScheduleIds = array_map('intval', $deleteIds);
                    }

                    // Ids only in the loop; the relations are eager-loaded once
                    // after it. Calling ->load() per row cost six queries per
                    // operation inside the transaction (audit finding #8).
                    $savedIds = [];
                    $updateIds = array_values(array_filter(array_map(
                        static fn (array $op): int => (int) ($op['id'] ?? 0),
                        $validated['operations'],
                    )));
                    $existing = $updateIds === []
                        ? collect()
                        : Schedule::query()->whereIn('id', $updateIds)->get()->keyBy('id');

                    foreach ($validated['operations'] as $op) {
                        if (isset($op['subject_id']) && ! isset($op['course_id'])) {
                            $op['course_id'] = $op['subject_id'];
                        }

                        if (isset($op['id'])) {
                            $schedule = $existing->get((int) $op['id']);
                            if (! $schedule) {
                                throw (new ModelNotFoundException)->setModel(Schedule::class, [$op['id']]);
                            }
                            $schedule->update($op);
                        } else {
                            $schedule = Schedule::create($op);
                        }
                        $savedIds[] = (int) $schedule->id;
                    }

                    $savedSchedules = Schedule::query()
                        ->whereIn('id', $savedIds)
                        ->with(['term', 'section', 'course', 'faculty', 'room', 'department'])
                        ->get()
                        ->sortBy(static fn (Schedule $schedule): int => array_search((int) $schedule->id, $savedIds, true))
                        ->values()
                        ->all();
                });
            });
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }

        ApiCache::forgetGroup('instructor_assignments.index');

        return response()->json([
            'message' => 'Batch schedule operation completed successfully.',
            'schedules' => $savedSchedules,
            'deleted_schedule_ids' => $deletedScheduleIds,
        ]);
    }

    /**
     * Serialize schedule writes that touch the same terms.
     *
     * The check-then-write race cannot be closed by row locks alone: the
     * colliding operation is usually an INSERT, and there is no existing row to
     * lock. A named advisory lock per term gives predictable serialization
     * without relying on InnoDB gap-lock behaviour, and without the deadlock
     * risk of taking wide ranges of row locks in varying orders.
     *
     * Acquired before the transaction and released after it commits, so no
     * window exists between validation and commit. Named locks are session
     * scoped rather than transaction scoped, hence the explicit release.
     *
     * Locks are taken in sorted term order by every caller so two requests
     * covering overlapping terms can never deadlock against each other.
     *
     * MySQL/MariaDB only. Other drivers (sqlite in tests) run the callback
     * directly — there is no cross-connection contention to guard there.
     *
     * @param  list<int>  $termIds
     */
    private function withScheduleWriteLock(array $termIds, callable $callback): mixed
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || $termIds === []) {
            return $callback();
        }

        $acquired = [];

        try {
            foreach ($termIds as $termId) {
                $lockName = sprintf('wicars:schedule-write:%d', $termId);
                $granted = $connection->selectOne(
                    'SELECT GET_LOCK(?, ?) AS granted',
                    [$lockName, self::SCHEDULE_LOCK_TIMEOUT_SECONDS],
                );

                if ((int) ($granted->granted ?? 0) !== 1) {
                    throw new ScheduleConflictException(
                        [[
                            'rule' => 'concurrent_write',
                            'message' => 'Another schedule save for this term is still in progress. Please retry in a moment.',
                        ]],
                        'Another schedule save for this term is still in progress. Please retry in a moment.',
                    );
                }

                $acquired[] = $lockName;
            }

            return $callback();
        } finally {
            foreach (array_reverse($acquired) as $lockName) {
                $connection->statement('DO RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    /**
     * Terms whose schedules a batch operation could affect, sorted so that all
     * callers acquire locks in a consistent order.
     *
     * @param  list<array<string, mixed>>  $operations
     * @param  list<int|string>  $deleteIds
     * @return list<int>
     */
    private function conflictScopeTermIds(array $operations, array $deleteIds): array
    {
        $termIds = collect($operations)
            ->pluck('term_id')
            ->filter()
            ->map('intval');

        if ($deleteIds !== []) {
            $termIds = $termIds->merge(
                Schedule::query()
                    ->whereIn('id', array_map('intval', $deleteIds))
                    ->pluck('term_id')
                    ->map('intval'),
            );
        }

        return $termIds->unique()->sort()->values()->all();
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
            'operations' => 'required|array',
            'operations.*.id' => 'nullable|integer|exists:schedules,id',
            'operations.*.term_id' => 'required|integer|exists:terms,id',
            'operations.*.section_id' => 'required|integer|exists:sections,id',
            'operations.*.course_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.subject_id' => 'sometimes|integer|exists:courses,id',
            'operations.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id' => 'nullable|integer|exists:rooms,id',
            'operations.*.department_id' => 'required|integer|exists:departments,id',
            'operations.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'operations.*.start_time' => 'required|date_format:H:i',
            'operations.*.end_time' => 'required|date_format:H:i|after:operations.*.start_time',
            'operations.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid' => 'sometimes|boolean',
            'operations.*.preferred_pattern' => ['nullable', 'string', 'max:20'],
            'operations.*.split_group_id' => 'nullable|string|max:36',
            'operations.*.meeting_type' => 'nullable|in:lecture,laboratory',
            'operations.*.meeting_index' => 'nullable|integer|min:1',
            'operations.*.status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer|exists:schedules,id',
        ]);

        $deleteIds = $validated['delete_ids'] ?? [];
        $validated['operations'] = array_map(
            fn (array $operation): array => $this->hydrateExistingScheduleOperation(
                $this->clearOnlineRoomId($operation)
            ),
            $validated['operations']
        );
        $resolvedOps = [];
        $resolvedOpsByOriginalIndex = [];
        $allViolations = [];

        foreach ($validated['operations'] as $operation) {
            if (
                ! isset($operation['id'])
                && isset($operation['department_id'])
                && ! $this->authorization->payloadBelongsToDepartment($request, (int) $operation['department_id'])
            ) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        $operationIds = collect($validated['operations'])
            ->pluck('id')
            ->filter()
            ->map('intval')
            ->all();

        if (! $this->authorization->scheduleIdsBelongToDepartment($request, $operationIds)
            || ! $this->authorization->scheduleIdsBelongToDepartment($request, $deleteIds)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $mergedIgnoreIds = array_values(array_unique(array_merge($operationIds, array_map('intval', $deleteIds))));

        $orderedOperations = $this->prioritizeSplitAnchorMeetings($validated['operations']);

        foreach ($orderedOperations as $orderedOperation) {
            $index = (int) $orderedOperation['index'];
            $op = $orderedOperation['operation'];
            if (isset($op['subject_id']) && ! isset($op['course_id'])) {
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
            $timeConflictRules = ['section_conflict', 'subject_section_time_conflict', 'room_conflict', 'faculty_conflict', 'split_group_day_separation'];
            $hasTimeConflict = collect($violations)->contains(
                fn ($v) => in_array($v['rule'] ?? '', $timeConflictRules, true)
            );

            if (! $hasTimeConflict) {
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
                        'course_code' => $courseCode,
                        'day' => $op['day'],
                        'start_time' => $op['start_time'],
                        'end_time' => $op['end_time'],
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
                    'rule' => 'split_unresolvable',
                    'operation_index' => $index,
                    'course_code' => $courseCode,
                    'day' => $op['day'],
                    'start_time' => $op['start_time'],
                    'end_time' => $op['end_time'],
                    'message' => "Could not find a conflict-free time slot for {$courseCode} on {$op['day']} "
                        ."starting at {$op['start_time']}. All slots within operating hours are occupied. "
                        .'Please resolve the conflict manually or change the split day.',
                ];
            }
        }

        if (! empty($allViolations)) {
            return response()->json([
                'status' => 'conflict',
                'message' => 'One or more split sessions could not be scheduled conflict-free.',
                'violations' => $allViolations,
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'All split sessions validated successfully.',
            'operations' => $this->restoreOriginalOperationOrder($resolvedOpsByOriginalIndex),
        ]);
    }

    private function prioritizeSplitAnchorMeetings(array $operations): array
    {
        return collect($operations)
            ->map(fn (array $operation, int $index): array => [
                'index' => $index,
                'operation' => $operation,
                'priority' => ! empty($operation['split_group_id']) && (int) ($operation['meeting_index'] ?? 1) === 1 ? 0 : 1,
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
        $origEndMins = $this->timeToMinutesLocal($op['end_time']);
        $durationMins = $origEndMins - $origStartMins;
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
            'Monday' => ['Wednesday', 'Friday', 'Tuesday', 'Thursday', 'Saturday'],
            'Tuesday' => ['Thursday', 'Wednesday', 'Monday', 'Friday', 'Saturday'],
            'Wednesday' => ['Monday', 'Friday', 'Thursday', 'Tuesday', 'Saturday'],
            'Thursday' => ['Tuesday', 'Friday', 'Wednesday', 'Monday', 'Saturday'],
            'Friday' => ['Wednesday', 'Monday', 'Thursday', 'Tuesday', 'Saturday'],
            'Saturday' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'Sunday' => ['Saturday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ];

        $alternateDays = $daySwaps[$op['day']] ?? [];
        foreach ($alternateDays as $altDay) {
            // Try original start time on alternate day
            $candidate = $op;
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
            static fn (int $left, int $right): int => abs($left - $originalStartMinutes) <=> abs($right - $originalStartMinutes)
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
        $courseId = (int) ($op['course_id'] ?? 0);
        $meetingType = $op['meeting_type'] ?? null;
        $mode = $op['mode'] ?? 'on-site';
        $deptId = (int) ($op['department_id'] ?? 0);

        // Determine the required room type for this operation.
        $course = $courseId > 0 ? Course::find($courseId) : null;
        $requiredRoomType = match (true) {
            $mode === 'online' => 'online',
            $mode === 'field' => 'field',
            $meetingType === 'lecture' => 'lecture',
            $meetingType === 'laboratory' => 'laboratory',
            $course !== null => SchedulingPolicy::effectiveRoomType($course, $meetingType),
            default => 'lecture',
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

        foreach ($candidateRooms as $room) {
            // Skip the original room — we already know it fails.
            if ((int) $room->id === (int) ($op['room_id'] ?? 0)) {
                continue;
            }

            $candidate = $op;
            $candidate['room_id'] = (int) $room->id;

            if (empty($this->validateCandidate($candidate, $resolvedOps))) {
                return $candidate;
            }
        }

        if ($course !== null && SchedulingPolicy::allowsRoomTbaFallback($course, $meetingType)) {
            $candidate = $op;
            $candidate['room_id'] = null;
            $candidate['mode'] = 'on-site';
            if (empty($this->validateCandidate($candidate, $resolvedOps))) {
                return $candidate;
            }
        }

        if ($course !== null && SchedulingPolicy::allowsOnlineRoomFallback($course, $meetingType)) {
            $candidate = $op;
            $candidate['room_id'] = null;
            $candidate['mode'] = 'online';
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

    /**
     * Intra-batch and against-persisted conflict checks for a batch payload.
     *
     * Delegates the rules to BatchConflictValidator so the batch save and the
     * recommendation-accept path cannot drift apart again; this method only
     * renders the result into the `operation_index` violation shape that the
     * batch endpoint's clients already parse.
     *
     * @param  list<array<string, mixed>>  $operations
     * @param  list<int>  $ignoreScheduleIds
     * @return list<array<string, mixed>>
     */
    private function checkIntraBatchConflicts(array $operations, array $ignoreScheduleIds = []): array
    {
        return array_map(
            static fn (BatchConflict $conflict): array => [
                'rule' => $conflict->rule,
                'operation_index' => $conflict->index,
                'course_code' => $conflict->courseCode ?? 'Course',
                'day' => $conflict->day,
                'message' => match ($conflict->rule) {
                    BatchConflict::RULE_SECTION => sprintf(
                        'Intra-batch Section Conflict: %s and %s overlap for section on %s from %s to %s.',
                        $conflict->otherCourseCode,
                        $conflict->courseCode,
                        $conflict->day,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                    ),
                    BatchConflict::RULE_SUBJECT_SECTION_TIME => sprintf(
                        'Intra-batch Subject/Section Conflict: %s is assigned to multiple sections at overlapping time %s-%s on %s.',
                        $conflict->otherCourseCode,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                        $conflict->day,
                    ),
                    BatchConflict::RULE_ROOM => sprintf(
                        'Intra-batch Room Conflict: Room is assigned to both %s and %s at overlapping time %s-%s on %s.',
                        $conflict->otherCourseCode,
                        $conflict->courseCode,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                        $conflict->day,
                    ),
                    BatchConflict::RULE_FACULTY => sprintf(
                        'Intra-batch Faculty Conflict: Instructor is assigned to teach both %s and %s at overlapping time %s-%s on %s.',
                        $conflict->otherCourseCode,
                        $conflict->courseCode,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                        $conflict->day,
                    ),
                    BatchConflict::RULE_ROOM_CAPACITY => sprintf(
                        'Intra-batch Room Capacity Conflict: FIELD allows only %d concurrent classes per department on %s from %s to %s.',
                        $conflict->capacity,
                        $conflict->day,
                        $conflict->overlapStart,
                        $conflict->overlapEnd,
                    ),
                    BatchConflict::RULE_ONLINE_CAPACITY => sprintf(
                        'Intra-batch Online Capacity Conflict: configured limit is %d concurrent classes per department on %s.',
                        $conflict->capacity,
                        $conflict->day,
                    ),
                    default => 'Intra-batch schedule conflict.',
                },
            ],
            $this->batchConflicts->validate($operations, $ignoreScheduleIds),
        );
    }

    /**
     * Instructor assignment is a post-VPAA-approval step: `InstructorAssignment`
     * only lists and writes rows in the assignable statuses, and withdrawal
     * releases the assignment precisely because the row left them. That rule was
     * enforced client-side only, so this is the server-side half.
     *
     * Only *introducing or changing* an instructor is gated. Clearing one stays
     * allowed at any status, and re-sending the value a row already holds is a
     * no-op — the plotting save carries `faculty_id` forward on relocate.
     *
     * @return string|null Error message when the write is not allowed at this stage.
     */
    private function instructorAssignmentStageError(Schedule $schedule, ?int $requestedFacultyId): ?string
    {
        $currentFacultyId = $schedule->faculty_id === null ? null : (int) $schedule->faculty_id;

        if ($requestedFacultyId === null || $requestedFacultyId === $currentFacultyId) {
            return null;
        }

        if ((bool) $schedule->faculty_assignment_done) {
            return 'Instructor assignments are marked done. Choose Edit Assignments before changing them.';
        }

        if (in_array($schedule->status, SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES, true)) {
            return null;
        }

        return $schedule->status === 'finalized'
            ? 'A finalized schedule cannot be reassigned.'
            : 'Instructor assignment is available only after VPAA approval.';
    }

    private function hydrateExistingScheduleOperation(array $operation): array
    {
        if (! isset($operation['id'])) {
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

        // Identity and ownership come from the persisted row. A client may
        // submit a stale or malicious department_id, but it must never affect
        // authorization or the RuleEngine payload for an existing schedule.
        $operation['department_id'] = $schedule->department_id;

        return array_merge($persisted, $operation);
    }

    public function show(Schedule $schedule)
    {
        return response()->json($schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department']));
    }

    public function byTerm(int|string $termId)
    {
        $schedules = Schedule::with(['term', 'section', 'course', 'faculty', 'room', 'department'])
            ->where('term_id', $termId)
            ->latest()
            ->limit(1000)
            ->get();

        return response()->json($schedules);
    }

    public function bySection(int|string $sectionId)
    {
        $schedules = Schedule::with(['term', 'section', 'course', 'faculty', 'room', 'department'])
            ->where('section_id', $sectionId)
            ->latest()
            ->limit(1000)
            ->get();

        return response()->json($schedules);
    }

    public function update(Request $request, Schedule $schedule)
    {
        if (! $request->has('course_id') && $request->has('subject_id')) {
            $request->merge(['course_id' => $request->input('subject_id')]);
        }

        $validated = $request->validate([
            'term_id' => 'sometimes|required|exists:terms,id',
            'section_id' => 'sometimes|required|exists:sections,id',
            'course_id' => 'sometimes|required|exists:courses,id',
            'faculty_id' => 'nullable|exists:faculties,id',
            'room_id' => 'sometimes|nullable|exists:rooms,id',
            'department_id' => 'sometimes|required|exists:departments,id',
            'day' => SchedulingPolicy::allowedDaysRule('sometimes'),
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i|after:start_time',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'split_group_id' => 'nullable|string|max:36',
            'meeting_type' => 'nullable|in:lecture,laboratory',
            'meeting_index' => 'nullable|integer|min:1',
            'status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
        ]);
        $validated = $this->clearOnlineRoomId($validated);

        if (! $this->authorization->scheduleBelongsToDepartment($request, $schedule)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        if (
            isset($validated['department_id'])
            && ! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])
        ) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        if (array_key_exists('faculty_id', $validated)) {
            $stageError = $this->instructorAssignmentStageError(
                $schedule,
                $validated['faculty_id'] === null ? null : (int) $validated['faculty_id'],
            );

            if ($stageError !== null) {
                return response()->json(['message' => $stageError], 422);
            }
        }

        // The timetable's slot popup and inline picker assign through this route,
        // so they need the same overload confirmation the dedicated assignment
        // page gets. Clearing faculty_id can never overload anyone, so only a
        // non-null assignment is gated. The flag is read straight off the request
        // rather than validated into $validated, which is mass-assigned below.
        $assignedFacultyId = ($validated['faculty_id'] ?? null) !== null
            ? (int) $validated['faculty_id']
            : null;

        if ($assignedFacultyId !== null) {
            $faculty = Faculty::query()->find($assignedFacultyId);
            // This route's faculty payload carries faculty_id on its own, so the
            // row's existing section and course are the pair being loaded.
            $pair = $faculty !== null ? $this->loadPairForSchedule($schedule) : null;

            if ($pair !== null) {
                $projection = $this->withAssignmentLabel(
                    $this->facultyLoad->projectLoad($faculty, $this->activeTermId(), [$pair]),
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
        }

        $plottingFields = [
            'term_id', 'section_id', 'course_id', 'subject_id', 'room_id', 'department_id',
            'day', 'start_time', 'end_time', 'mode', 'is_hybrid', 'preferred_pattern',
            'split_group_id', 'meeting_type', 'meeting_index', 'status',
        ];
        $changesPlotting = collect($plottingFields)->contains(
            static fn (string $field): bool => array_key_exists($field, $validated)
        );
        if ($changesPlotting && ! in_array($schedule->status, self::PLOTTING_EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This schedule is locked at its current approval stage. Withdraw or return it to revision before editing the timetable.',
            ], 422);
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== $schedule->status) {
            return response()->json([
                'message' => 'Schedule status must be changed through the approval workflow.',
            ], 422);
        }

        $attemptData = array_merge($schedule->toArray(), $validated, ['ignore_schedule_id' => $schedule->id]);

        // Same check-then-write race as batch(): validate and write under one
        // lock and one transaction so a concurrent save cannot land between
        // them. This is the path drag-relocate and faculty assignment use.
        $termId = (int) ($validated['term_id'] ?? $schedule->term_id);

        try {
            $this->withScheduleWriteLock($termId > 0 ? [$termId] : [], function () use ($schedule, $validated, $attemptData): void {
                DB::transaction(function () use ($schedule, $validated, $attemptData): void {
                    $violations = $this->ruleEngine->validate($attemptData);

                    if (! empty($violations)) {
                        throw new ScheduleConflictException($violations, 'Schedule update conflicts with existing entries.');
                    }

                    $schedule->update($validated);
                });
            });
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }

        $schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department']);
        $this->notifyScheduleSaved($request, $schedule, 'updated');

        return response()->json($schedule);
    }

    public function destroy(Request $request, Schedule $schedule)
    {
        if (! $this->authorization->scheduleBelongsToDepartment($request, $schedule)) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $schedule->load(['term', 'section', 'course', 'faculty', 'room', 'department', 'split']);
        $deletedSchedule = clone $schedule;

        $splitGroupId = $schedule->split_group_id;

        if ($request->query('delete_group') === 'true' && $splitGroupId) {
            $schedules = Schedule::whereHas('split', function ($q) use ($splitGroupId) {
                $q->where('split_group_id', $splitGroupId);
            })->get();

            if (! $this->authorization->scheduleIdsBelongToDepartment($request, $schedules->pluck('id')->all())) {
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
        $roomId = (int) ($payload['room_id'] ?? 0);
        if ($roomId > 0) {
            $roomType = Rooms::query()
                ->whereKey($roomId)
                ->value('room_type');

            if ($roomType === 'online') {
                $payload['mode'] = 'online';
            }
        }

        if (($payload['mode'] ?? null) === 'online') {
            $payload['room_id'] = null;
        }

        return $payload;
    }

    /**
     * A delegated course keeps the source department's timetable. Once the
     * source has created a row for the active term, the receiving department may
     * only assign its instructor through InstructorAssignmentController; it must
     * never create a second section/time/room for the same course.
     */
    private function delegatedCourseScheduleMessage(array $payload): ?string
    {
        $courseId = (int) ($payload['course_id'] ?? $payload['subject_id'] ?? 0);
        $targetDepartmentId = (int) ($payload['department_id'] ?? 0);
        $termId = (int) ($payload['term_id'] ?? 0);

        if ($courseId === 0 || $targetDepartmentId === 0 || $termId === 0) {
            return null;
        }

        $course = Course::query()->find($courseId);
        $teachingDepartmentId = (int) ($course?->teaching_department_id ?? 0);
        if ($course === null || $teachingDepartmentId === 0 || $teachingDepartmentId !== $targetDepartmentId) {
            return null;
        }

        $sourceSchedule = Schedule::query()
            ->where('term_id', $termId)
            ->where('course_id', $courseId)
            ->where('department_id', '!=', $targetDepartmentId)
            ->whereNotIn('status', ['rejected', 'revision'])
            ->first();

        if ($sourceSchedule === null) {
            return null;
        }

        return "{$course->course_code} already has a schedule owned by the source department. Assign the instructor to the existing schedule; do not create another schedule.";
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

        return ! Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->where('department_id', '!=', $scope)
            ->exists();
    }

    /**
     * Whether the acting user may assign instructors to all of these schedules.
     *
     * Not the same set as scheduleIdsBelongToDepartment: assignment follows the
     * college that *teaches* a course, which is not always the one that owns the
     * offering. IT owns GEC 101 and CAS teaches it, so CAS must be able to assign
     * instructors to IT's GEC 101 rows — and, for the same reason, IT must not,
     * since the rule engine would only accept CAS instructors there anyway. That is
     * the answer InstructorAssignmentController::update already gives on the
     * single-row route; this keeps the batch route consistent with it.
     *
     * Kept separate rather than widening scheduleIdsBelongToDepartment, which
     * batchStatus also uses: being delegated a course is not licence to move another
     * department's schedules through the approval workflow.
     */
    private function scheduleIdsAssignableByDepartment(Request $request, array $scheduleIds): bool
    {
        $scope = $this->departmentScope($request);
        if ($scope === null || $scheduleIds === []) {
            return true;
        }

        // Its own query rather than an eager load: the caller's Schedule models are
        // fetched relation-free because toArray() on them feeds RuleEngine::validate(),
        // and a loaded relation corrupts that payload.
        $delegations = Course::query()
            ->whereNotNull('teaching_department_id')
            ->pluck('teaching_department_id', 'id')
            ->all();

        $delegatedHere = [];
        $delegatedElsewhere = [];
        foreach ($delegations as $courseId => $teachingDepartmentId) {
            if ((int) $teachingDepartmentId === $scope) {
                $delegatedHere[] = (int) $courseId;
            } else {
                $delegatedElsewhere[] = (int) $courseId;
            }
        }

        // Asks the inverse — "is any of these rows one this department may not
        // assign?" — so one existence check covers the whole batch.
        return ! Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->where(fn ($row) => $row
                // Not delegated to this department...
                ->when($delegatedHere !== [], fn ($query) => $query->where(
                    // The null-course branch matters: `course_id NOT IN (...)` is
                    // NULL for a row with no course, which would drop it from this
                    // check and quietly admit it.
                    fn ($scoped) => $scoped
                        ->whereNull('course_id')
                        ->orWhereNotIn('course_id', $delegatedHere),
                ))
                // ...and not this department's to assign on its own either: another
                // college owns the offering, or this course was handed to a third.
                ->where(fn ($foreign) => $foreign
                    ->where('department_id', '!=', $scope)
                    ->when(
                        $delegatedElsewhere !== [],
                        fn ($query) => $query->orWhereIn('course_id', $delegatedElsewhere),
                    )))
            ->exists();
    }

    private function sectionIdsBelongToDepartment(Request $request, array $sectionIds): bool
    {
        $scope = $this->departmentScope($request);
        if ($scope === null || $sectionIds === []) {
            return true;
        }

        return ! DB::table('sections')
            ->whereIn('id', array_values(array_unique(array_map('intval', $sectionIds))))
            ->where('department_id', '!=', $scope)
            ->exists();
    }

    private function notifyScheduleSaved(Request $request, Schedule $schedule, string $action): void
    {
        // Source-department timetable changes affect the receiving department's
        // instructor-assignment workspace when the course is delegated.
        ApiCache::forgetGroup('instructor_assignments.index');

        $actor = $request->user();
        if (! $actor) {
            return;
        }

        $courseCode = $schedule->course?->course_code ?? 'Course';
        $sectionName = $schedule->section?->section_name ?? 'Section';

        $this->notifications->notifyRoles(
            ['vpaa', 'dean'],
            'schedule_activity',
            'Schedule '.ucfirst($action),
            "{$actor->name} {$action} schedule for {$courseCode} ({$sectionName}).",
            $actor,
            $schedule->department_id,
            $schedule->term_id
        );
    }

    /**
     * Assign (or clear) instructors for many schedules in one transaction.
     *
     * Auto-Assign used to issue one PUT per schedule. Nothing spanned those
     * requests, so a failure partway through left the earlier assignments
     * committed while the user was told the operation failed. Validating and
     * writing here under the same term lock and transaction that batch() uses
     * makes the set all-or-nothing, and because each row is written before the
     * next is validated, the RuleEngine sees the in-flight assignments and can
     * catch two schedules being given the same instructor at the same hour.
     */
    public function batchFaculty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assignments' => 'required|array|min:1',
            'assignments.*.schedule_ids' => 'required|array|min:1',
            'assignments.*.schedule_ids.*' => 'integer|exists:schedules,id',
            'assignments.*.faculty_id' => 'nullable|integer|exists:faculties,id',
        ]);

        $scheduleIds = [];
        foreach ($validated['assignments'] as $assignment) {
            foreach ($assignment['schedule_ids'] as $scheduleId) {
                $scheduleIds[] = (int) $scheduleId;
            }
        }

        if (count($scheduleIds) !== count(array_unique($scheduleIds))) {
            return response()->json([
                'message' => 'A schedule cannot appear in more than one assignment.',
            ], 422);
        }

        if (! $this->authorization->scheduleIdsAssignableByDepartment($request, $scheduleIds)) {
            return response()->json([
                'message' => 'You can only assign instructors for schedules your department owns or teaches.',
            ], 403);
        }

        $programId = $request->user()?->role === 'program_head'
            ? (int) ($request->user()?->program_id ?? 0)
            : null;
        if ($programId !== null) {
            $facultyIds = collect($validated['assignments'])
                ->pluck('faculty_id')
                ->filter(fn ($facultyId) => $facultyId !== null)
                ->map('intval')
                ->unique()
                ->values();
            $matchingFacultyCount = Faculty::query()
                ->whereIn('id', $facultyIds)
                ->where('program_id', $programId)
                ->count();

            if ($matchingFacultyCount !== $facultyIds->count()) {
                return response()->json([
                    'message' => 'Program Heads can only assign instructors from their assigned program.',
                ], 422);
            }
        }

        // Deliberately relation-free: toArray() on these models is what feeds
        // RuleEngine::validate() below, and an eager-loaded relation corrupts it.
        $schedules = Schedule::query()
            ->whereIn('id', $scheduleIds)
            ->get()
            ->keyBy(static fn (Schedule $schedule): int => (int) $schedule->id);

        if ($programId !== null) {
            $programScheduleCount = Schedule::query()
                ->whereIn('id', $scheduleIds)
                ->whereHas('course', fn ($course) => $course
                    ->where('program_id', $programId)
                    ->orWhere('teaching_program_id', $programId))
                ->count();

            if ($programScheduleCount !== count($scheduleIds)) {
                return response()->json([
                    'message' => 'Program Heads can only assign courses assigned to their program.',
                ], 403);
            }
        }

        foreach ($validated['assignments'] as $assignment) {
            $facultyId = isset($assignment['faculty_id']) ? (int) $assignment['faculty_id'] : null;

            foreach ($assignment['schedule_ids'] as $scheduleId) {
                $schedule = $schedules->get((int) $scheduleId);
                if ($schedule === null) {
                    continue;
                }

                $stageError = $this->instructorAssignmentStageError($schedule, $facultyId);
                if ($stageError !== null) {
                    return response()->json([
                        'message' => $stageError,
                        'violations' => [[
                            'rule' => 'instructor_assignment_stage',
                            'schedule_id' => (int) $schedule->id,
                            'message' => $stageError,
                        ]],
                    ], 422);
                }
            }
        }

        // Bulk assignment gets one confirmation per instructor rather than one per
        // class: the whole batch is projected onto each instructor at once, so the
        // prompt reports the load they actually end up carrying. Clearing an
        // instructor can never overload anyone, so null assignments are skipped.
        {
            $rowsByFaculty = [];

            foreach ($validated['assignments'] as $assignment) {
                $facultyId = isset($assignment['faculty_id']) ? (int) $assignment['faculty_id'] : null;

                if ($facultyId === null) {
                    continue;
                }

                foreach ($assignment['schedule_ids'] as $scheduleId) {
                    $schedule = $schedules->get((int) $scheduleId);

                    if ($schedule !== null) {
                        $rowsByFaculty[$facultyId][] = $schedule;
                    }
                }
            }

            $projections = [];

            if ($rowsByFaculty !== []) {
                $activeTermId = $this->activeTermId();
                $faculties = Faculty::query()->whereIn('id', array_keys($rowsByFaculty))->get();

                foreach ($faculties as $faculty) {
                    $rows = $rowsByFaculty[(int) $faculty->id] ?? [];
                    $pairs = $this->loadPairsForSchedules($rows);

                    $projections[] = $this->withAssignmentLabel(
                        $this->facultyLoad->projectLoad($faculty, $activeTermId, $pairs),
                        $this->assignmentLabelForClasses($rows, count($pairs)),
                    );
                }
            }

            $ceilingError = $this->facultyCeilingExceededResponse($projections);
            if ($ceilingError !== null) {
                return $ceilingError;
            }

            if (! $request->boolean('confirm_overload')) {
                $confirmation = $this->overloadConfirmationResponse($projections);

                if ($confirmation !== null) {
                    return $confirmation;
                }
            }
        }

        $termIds = $schedules
            ->pluck('term_id')
            ->filter()
            ->map('intval')
            ->unique()
            ->sort()
            ->values()
            ->all();

        try {
            $this->withScheduleWriteLock($termIds, function () use ($validated, $schedules): void {
                DB::transaction(function () use ($validated, $schedules): void {
                    foreach ($validated['assignments'] as $assignment) {
                        $facultyId = $assignment['faculty_id'] ?? null;
                        $facultyId = $facultyId === null ? null : (int) $facultyId;

                        foreach ($assignment['schedule_ids'] as $scheduleId) {
                            $schedule = $schedules->get((int) $scheduleId);
                            if ($schedule === null) {
                                throw new ScheduleConflictException(
                                    [[
                                        'rule' => 'missing_schedule',
                                        'schedule_id' => (int) $scheduleId,
                                        'message' => 'A selected schedule no longer exists.',
                                    ]],
                                    'A selected schedule no longer exists.',
                                );
                            }

                            $violations = $this->ruleEngine->validate(array_merge(
                                $schedule->toArray(),
                                [
                                    'faculty_id' => $facultyId,
                                    'ignore_schedule_id' => (int) $schedule->id,
                                ],
                            ));

                            if (! empty($violations)) {
                                throw new ScheduleConflictException(
                                    array_map(
                                        static fn (array $violation): array => array_merge($violation, [
                                            'schedule_id' => (int) $schedule->id,
                                        ]),
                                        $violations,
                                    ),
                                    'Instructor assignment conflicts with existing entries.',
                                );
                            }

                            $schedule->update(['faculty_id' => $facultyId]);
                        }
                    }
                });
            });
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }

        ApiCache::forgetGroup('instructor_assignments.index');

        return response()->json([
            'message' => 'Instructor assignments completed successfully.',
            'schedules' => Schedule::query()
                ->whereIn('id', $scheduleIds)
                ->with(['term', 'section', 'course', 'faculty', 'room', 'department'])
                ->get(),
            'schedules_updated' => count($scheduleIds),
        ]);
    }

    public function batchFacultyDone(Request $request): JsonResponse
    {
        $validated = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'exists:schedules,id'], 'done' => ['required', 'boolean']]);
        if (! $this->authorization->scheduleIdsAssignableByDepartment($request, $validated['ids'])) {
            return response()->json(['message' => 'You can only update instructor assignments for your department.'], 403);
        }
        $schedules = Schedule::query()->whereIn('id', $validated['ids'])->get();
        if ($validated['done'] && $schedules->contains(fn (Schedule $schedule) => $schedule->faculty_id === null)) {
            return response()->json(['message' => 'Assign instructors to every schedule before marking done.'], 422);
        }
        $schedules->each(fn (Schedule $schedule) => $schedule->update(['faculty_assignment_done' => (bool) $validated['done']]));
        ApiCache::forgetGroup('instructor_assignments.index');
        if ($validated['done'] && $schedules->isNotEmpty()) {
            $first = $schedules->first()->fresh(['course.department', 'course.teachingDepartment']);
            $this->notifications->notifyCrossDepartmentCompletion($first, $request->user(), $schedules->modelKeys());
        }
        return response()->json(['schedules' => Schedule::query()->whereIn('id', $validated['ids'])->with(['term', 'section', 'course', 'faculty', 'room', 'department'])->get()]);
    }

    public function batchStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:schedules,id',
            'status' => SchedulingPolicy::allowedScheduleStatusesRule('required'),
        ]);

        if (! $this->authorization->scheduleIdsBelongToDepartment($request, $validated['ids'])) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        if ($validated['status'] === 'finalized' && Schedule::query()
            ->whereIn('id', $validated['ids'])
            ->whereNull('room_id')
            ->where('mode', 'on-site')
            ->whereHas('course', fn ($query) => $query->where('room_type_required', 'laboratory')->orWhere('lab_hours', '>', 0))
            ->exists()) {
            return response()->json([
                'message' => 'Cannot finalize while one or more laboratory schedules still have Room TBA. Assign all rooms first.',
            ], 422);
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
