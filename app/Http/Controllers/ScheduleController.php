<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Subjects;
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

    // Get all schedules
    public function index(Request $request)
    {
        $schedules = Schedule::with([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ])
            ->when($this->departmentScope($request) !== null, fn ($query) => $query->where('department_id', $this->departmentScope($request)))
            ->latest()
            ->get();

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
        $validated = $request->validate([
            'term_id'           => 'required|exists:terms,id',
            'section_id'        => 'required|exists:sections,id',
            'subject_id'        => 'required|exists:subjects,id',
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

        // ── Rule Engine validation BEFORE saving ──
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
        $schedule->load(['term', 'section', 'subject', 'faculty', 'room', 'department']);
        $this->notifyScheduleSaved($request, $schedule, 'created');

        return response()->json($schedule, 201);
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => 'required|array|min:1',
            'operations.*.id' => 'nullable|integer|exists:schedules,id',
            'operations.*.term_id' => 'required_without:operations.*.id|integer|exists:terms,id',
            'operations.*.section_id' => 'required_without:operations.*.id|integer|exists:sections,id',
            'operations.*.subject_id' => 'required_without:operations.*.id|integer|exists:subjects,id',
            'operations.*.faculty_id' => 'nullable|integer|exists:faculties,id',
            'operations.*.room_id' => 'required_without:operations.*.id|integer|exists:rooms,id',
            'operations.*.department_id' => 'required_without:operations.*.id|integer|exists:departments,id',
            'operations.*.day' => SchedulingPolicy::allowedDaysRule('required'),
            'operations.*.start_time' => 'required|date_format:H:i',
            'operations.*.end_time' => 'required|date_format:H:i',
            'operations.*.mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'operations.*.is_hybrid' => 'sometimes|boolean',
            'operations.*.preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'operations.*.status' => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'delete_ids' => 'sometimes|array',
            'delete_ids.*' => 'integer|exists:schedules,id',
        ]);

        $departmentId = $this->departmentScope($request);
        if ($departmentId !== null) {
            foreach ($validated['operations'] as $operation) {
                if (isset($operation['department_id']) && (int) $operation['department_id'] !== $departmentId) {
                    return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
                }
            }

            $requestedScheduleIds = collect($validated['operations'])
                ->pluck('id')
                ->merge($validated['delete_ids'] ?? [])
                ->filter()
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            if (
                $requestedScheduleIds->isNotEmpty()
                && Schedule::query()
                    ->whereIn('id', $requestedScheduleIds)
                    ->where('department_id', '!=', $departmentId)
                    ->exists()
            ) {
                return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
            }
        }

        try {
            $result = DB::transaction(function () use ($validated, $request): array {
                $deleteIds = array_values(array_unique(array_map(
                    static fn (mixed $id): int => (int) $id,
                    $validated['delete_ids'] ?? [],
                )));

                $operationIds = collect($validated['operations'])
                    ->pluck('id')
                    ->filter()
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();

                $affectedIds = array_values(array_unique(array_merge($deleteIds, $operationIds)));

                $existingSchedules = $affectedIds === []
                    ? collect()
                    : Schedule::query()
                        ->whereIn('id', $affectedIds)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                $rows = [];
                foreach ($validated['operations'] as $index => $operation) {
                    $base = [];

                    if (!empty($operation['id'])) {
                        $existing = $existingSchedules->get((int) $operation['id']);
                        if (!$existing) {
                            throw new AtomicScheduleValidationException([[
                                'rule' => 'schedule_exists',
                                'message' => 'A schedule block selected for update no longer exists.',
                                'operation_row' => $index,
                            ]]);
                        }

                        $base = $existing->toArray();
                    }

                    $row = array_merge($base, $operation);
                    $row['faculty_id'] = $row['faculty_id'] ?? null;
                    $row['mode'] = $row['mode'] ?? 'on-site';
                    $row['is_hybrid'] = (bool) ($row['is_hybrid'] ?? false);
                    $row['preferred_pattern'] = $row['preferred_pattern'] ?? null;
                    $row['status'] = $row['status'] ?? 'draft';
                    $row['ignore_schedule_id'] = $affectedIds;

                    if (
                        array_key_exists('faculty_id', $operation)
                        && !$this->canManageFacultyForRow($request, $row)
                    ) {
                        throw new AtomicScheduleValidationException([[
                            'rule' => 'gec_faculty_assignment_permission',
                            'message' => 'Only the CAS Department can assign or remove instructors for GEC subjects.',
                            'operation_row' => $index,
                        ]]);
                    }

                    $rows[] = $row;
                }

                $violations = $this->validateAtomicRows($rows);

                foreach ($rows as $index => $row) {
                    $rowViolations = $this->ruleEngine->validate($row);
                    foreach ($rowViolations as $violation) {
                        $violation['operation_row'] = $index;
                        $violations[] = $violation;
                    }
                }

                if ($violations !== []) {
                    throw new AtomicScheduleValidationException($violations);
                }

                if ($deleteIds !== []) {
                    Schedule::query()->whereIn('id', $deleteIds)->delete();
                }

                $savedSchedules = [];
                foreach ($rows as $row) {
                    unset($row['ignore_schedule_id']);

                    if (!empty($row['id'])) {
                        $schedule = Schedule::query()->findOrFail($row['id']);
                        $schedule->update($row);
                    } else {
                        $schedule = Schedule::create($row);
                    }

                    $savedSchedules[] = $schedule->load([
                        'term', 'section', 'subject', 'faculty', 'room', 'department'
                    ]);
                }

                return [
                    'schedules' => $savedSchedules,
                    'deleted_schedule_ids' => $deleteIds,
                ];
            });
        } catch (AtomicScheduleValidationException $exception) {
            return response()->json([
                'message' => 'Schedule blocks failed atomic validation.',
                'violations' => $exception->violations,
            ], 422);
        }

        $this->notifyBatchSaved($request, $result['schedules']);

        return response()->json([
            'message' => 'Schedule blocks saved successfully.',
            'schedules' => $result['schedules'],
            'deleted_schedule_ids' => $result['deleted_schedule_ids'],
        ]);
    }

    // Get single schedule
    public function show(Request $request, Schedule $schedule)
    {
        if (!$this->canAccessSchedule($request, $schedule)) {
            return response()->json(['message' => 'Schedule not found in your department.'], 404);
        }

        return response()->json($schedule->load([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ]));
    }

    // Update schedule
    public function update(Request $request, Schedule $schedule)
    {
        if (!$this->canAccessSchedule($request, $schedule)) {
            return response()->json(['message' => 'Schedule not found in your department.'], 404);
        }

        $validated = $request->validate([
            'term_id'           => 'sometimes|exists:terms,id',
            'section_id'        => 'sometimes|exists:sections,id',
            'subject_id'        => 'sometimes|exists:subjects,id',
            'faculty_id'        => 'nullable|exists:faculties,id',
            'room_id'           => 'sometimes|exists:rooms,id',
            'department_id'     => 'sometimes|exists:departments,id',
            'day'               => SchedulingPolicy::allowedDaysRule('sometimes'),
            'start_time'        => 'sometimes|date_format:H:i',
            'end_time'          => 'sometimes|date_format:H:i|after:start_time',
            'mode'              => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid'         => 'sometimes|boolean',
            'preferred_pattern' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'status'            => SchedulingPolicy::allowedScheduleStatusesRule('sometimes'),
            'rejection_reason'  => 'nullable|string',
            'reviewed_by_dean'  => 'nullable|exists:users,id',
            'reviewed_at_dean'  => 'nullable',
            'approved_by_vpaa'  => 'nullable|exists:users,id',
            'approved_at_vpaa'  => 'nullable',
        ]);

        // ── Rule Engine validation — merge existing + incoming so partial
        //    updates (e.g. just changing room_id) still validate the FULL slot ──
        if (
            isset($validated['department_id'])
            && !$this->payloadBelongsToDepartment($request, (int) $validated['department_id'])
        ) {
            return response()->json(['message' => 'You can only manage schedules for your department.'], 403);
        }

        $isFacultyOnlyUpdate = array_key_exists('faculty_id', $validated)
            && count(array_diff(array_keys($validated), ['faculty_id'])) === 0;
        if ($isFacultyOnlyUpdate && !$this->canManageScheduleFaculty($request, $schedule)) {
            return response()->json([
                'message' => 'Only the CAS Department can assign or remove instructors for GEC subjects.',
            ], 403);
        }

        $linkedSchedules = $isFacultyOnlyUpdate
            ? $this->linkedMeetingBlocks($schedule)
            : collect([$schedule]);
        if ($linkedSchedules->isEmpty()) {
            $linkedSchedules = collect([$schedule]);
        }
        $linkedScheduleIds = $linkedSchedules->pluck('id')->all();
        $violations = [];

        foreach ($linkedSchedules as $linkedSchedule) {
            $attempt = array_merge($linkedSchedule->toArray(), $validated);
            $attempt['ignore_schedule_id'] = $linkedScheduleIds;
            $violations = array_merge($violations, $this->ruleEngine->validate($attempt));
        }

        if (!empty($violations)) {
            return response()->json([
                'message'    => 'Schedule conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

        $previousStatus = $schedule->status;
        if ($isFacultyOnlyUpdate) {
            Schedule::query()
                ->whereIn('id', $linkedScheduleIds)
                ->update($validated);
            $updatedSchedules = Schedule::query()
                ->with(['term', 'section', 'subject', 'faculty', 'room', 'department'])
                ->whereIn('id', $linkedScheduleIds)
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();
            $schedule = $updatedSchedules->firstWhere('id', $schedule->id) ?? $updatedSchedules->first();
        } else {
            $schedule->update($validated);
            $schedule->load(['term', 'section', 'subject', 'faculty', 'room', 'department']);
            $updatedSchedules = collect([$schedule]);
        }

        $action = isset($validated['status']) && $validated['status'] !== $previousStatus
            ? "updated status to {$validated['status']}"
            : 'updated';

        $this->notifyScheduleSaved($request, $schedule, $action);

        if ($isFacultyOnlyUpdate) {
            return response()->json([
                'schedule' => $schedule,
                'schedules' => $updatedSchedules,
            ]);
        }

        return response()->json($schedule);
    }

    // Delete schedule
    public function destroy(Request $request, Schedule $schedule)
    {
        if (!$this->canAccessSchedule($request, $schedule)) {
            return response()->json(['message' => 'Schedule not found in your department.'], 404);
        }

        $schedule->delete();
        return response()->json(['message' => 'Schedule deleted successfully']);
    }

    private function linkedMeetingBlocks(Schedule $schedule)
    {
        return Schedule::query()
            ->where('term_id', $schedule->term_id)
            ->where('section_id', $schedule->section_id)
            ->where('subject_id', $schedule->subject_id)
            ->where('department_id', $schedule->department_id)
            ->where('preferred_pattern', $schedule->preferred_pattern)
            ->whereIn('status', ['approved', 'faculty_assignment'])
            ->get();
    }

    // Get schedules by term
    public function byTerm(Request $request, $termId)
    {
        $schedules = Schedule::with([
            'section', 'subject', 'faculty', 'room', 'department'
        ])
            ->where('term_id', $termId)
            ->when($this->departmentScope($request) !== null, fn ($query) => $query->where('department_id', $this->departmentScope($request)))
            ->get();

        return response()->json($schedules);
    }

    // Get schedules by section
    public function bySection(Request $request, $sectionId)
    {
        $schedules = Schedule::with([
            'subject', 'faculty', 'room'
        ])
            ->where('section_id', $sectionId)
            ->when($this->departmentScope($request) !== null, fn ($query) => $query->where('department_id', $this->departmentScope($request)))
            ->get();

        return response()->json($schedules);
    }

    private function departmentScope(Request $request): ?int
    {
        $user = $request->user();
        if (!$user || $user->isVpaa()) {
            return null;
        }

        return $user->department_id !== null ? (int) $user->department_id : null;
    }

    private function canAccessSchedule(Request $request, Schedule $schedule): bool
    {
        $departmentId = $this->departmentScope($request);
        return $departmentId === null || (int) $schedule->department_id === $departmentId;
    }

    private function payloadBelongsToDepartment(Request $request, int $departmentId): bool
    {
        $scopeDepartmentId = $this->departmentScope($request);
        return $scopeDepartmentId === null || $departmentId === $scopeDepartmentId;
    }

    private function canManageScheduleFaculty(Request $request, Schedule $schedule): bool
    {
        $schedule->loadMissing('subject');
        if ($schedule->subject?->subject_category !== 'gec') {
            return true;
        }

        $userDepartmentId = $request->user()?->department_id;
        $subjectDepartmentId = $schedule->subject?->department_id;

        return $userDepartmentId !== null
            && $subjectDepartmentId !== null
            && (int) $userDepartmentId === (int) $subjectDepartmentId;
    }

    private function canManageFacultyForRow(Request $request, array $row): bool
    {
        $subjectId = $row['subject_id'] ?? null;
        if (!$subjectId) {
            return true;
        }

        $subject = Subjects::query()->find($subjectId);
        if ($subject?->subject_category !== 'gec') {
            return true;
        }

        $userDepartmentId = $request->user()?->department_id;
        return $userDepartmentId !== null
            && $subject->department_id !== null
            && (int) $userDepartmentId === (int) $subject->department_id;
    }

    private function validateAtomicRows(array $rows): array
    {
        $violations = [];

        foreach ($rows as $leftIndex => $left) {
            foreach ($rows as $rightIndex => $right) {
                if ($leftIndex >= $rightIndex) {
                    continue;
                }

                if ((int) $left['term_id'] !== (int) $right['term_id'] || $left['day'] !== $right['day']) {
                    continue;
                }

                if (!$this->timesOverlap($left['start_time'], $left['end_time'], $right['start_time'], $right['end_time'])) {
                    continue;
                }

                if ((int) $left['section_id'] === (int) $right['section_id']) {
                    $violations[] = $this->batchViolation('section_conflict', $leftIndex, $rightIndex);
                }

                if ((int) $left['room_id'] === (int) $right['room_id']) {
                    $violations[] = $this->batchViolation('room_conflict', $leftIndex, $rightIndex);
                }

                if (
                    !empty($left['faculty_id'])
                    && !empty($right['faculty_id'])
                    && (int) $left['faculty_id'] === (int) $right['faculty_id']
                ) {
                    $violations[] = $this->batchViolation('faculty_conflict', $leftIndex, $rightIndex);
                }
            }
        }

        return $violations;
    }

    private function timesOverlap(string $leftStart, string $leftEnd, string $rightStart, string $rightEnd): bool
    {
        return SchedulingPolicy::normalizeTime($leftStart) < SchedulingPolicy::normalizeTime($rightEnd)
            && SchedulingPolicy::normalizeTime($rightStart) < SchedulingPolicy::normalizeTime($leftEnd);
    }

    private function batchViolation(string $rule, int $leftIndex, int $rightIndex): array
    {
        return [
            'rule' => $rule,
            'message' => "Schedule blocks {$leftIndex} and {$rightIndex} overlap in the same atomic operation.",
            'operation_rows' => [$leftIndex, $rightIndex],
        ];
    }

    /**
     * @param array<int, Schedule> $schedules
     */
    private function notifyBatchSaved(Request $request, array $schedules): void
    {
        $user = $request->user();
        if (!$user || $schedules === []) {
            return;
        }

        collect($schedules)
            ->groupBy(fn (Schedule $schedule): string => "{$schedule->department_id}:{$schedule->term_id}")
            ->each(function ($group) use ($user): void {
                /** @var Schedule $schedule */
                $schedule = $group->first();
                $schedule->loadMissing(['department', 'term']);

                if (!$schedule->department) {
                    return;
                }

                $this->notifications->notifyRoles(
                    ['secretary', 'program_head', 'dean'],
                    'schedule_updated',
                    'Department schedule updated',
                    $this->notifications->departmentWorkflowMessage(
                        'updated',
                        $schedule->department,
                        $schedule->term,
                        $user,
                        $group->count(),
                    ),
                    $user,
                    $schedule->department_id,
                    $schedule->term_id,
                    null,
                    [
                        'schedules_updated' => $group->count(),
                        'source' => 'batch',
                    ],
                );
            });
    }

    private function notifyScheduleSaved(Request $request, Schedule $schedule, string $action): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        $schedule->loadMissing(['department', 'term']);
        if (!$schedule->department) {
            return;
        }

        $this->notifications->notifyRoles(
            ['secretary', 'program_head', 'dean'],
            'schedule_updated',
            'Schedule updated',
            $this->notifications->departmentWorkflowMessage(
                $action,
                $schedule->department,
                $schedule->term,
                $user,
                1,
            ),
            $user,
            $schedule->department_id,
            $schedule->term_id,
            null,
            [
                'schedule_id' => $schedule->id,
                'section_id' => $schedule->section_id,
                'subject_id' => $schedule->subject_id,
                'status' => $schedule->status,
            ],
        );
    }
}

class AtomicScheduleValidationException extends \RuntimeException
{
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Atomic schedule validation failed.');
    }
}
