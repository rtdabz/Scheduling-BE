<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    protected RuleEngine $ruleEngine;

    public function __construct(RuleEngine $ruleEngine)
    {
        $this->ruleEngine = $ruleEngine;
    }

    // Get all schedules
    public function index()
    {
        $schedules = Schedule::with([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ])->latest()->get();

        return response()->json($schedules);
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
        $violations = $this->ruleEngine->validate($validated);

        if (!empty($violations)) {
            return response()->json([
                'message'    => 'Schedule conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

        $schedule = Schedule::create($validated);

        return response()->json($schedule->load([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ]), 201);
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

        try {
            $result = DB::transaction(function () use ($validated): array {
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

        return response()->json([
            'message' => 'Schedule blocks saved successfully.',
            'schedules' => $result['schedules'],
            'deleted_schedule_ids' => $result['deleted_schedule_ids'],
        ]);
    }

    // Get single schedule
    public function show(Schedule $schedule)
    {
        return response()->json($schedule->load([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ]));
    }

    // Update schedule
    public function update(Request $request, Schedule $schedule)
    {
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
        $attempt = array_merge($schedule->toArray(), $validated);
        $attempt['ignore_schedule_id'] = $schedule->id;

        // Only run conflict checks if scheduling-relevant fields are present
        $violations = $this->ruleEngine->validate($attempt);

        if (!empty($violations)) {
            return response()->json([
                'message'    => 'Schedule conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

        $schedule->update($validated);

        return response()->json($schedule->load([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ]));
    }

    // Delete schedule
    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return response()->json(['message' => 'Schedule deleted successfully']);
    }

    // Get schedules by term
    public function byTerm($termId)
    {
        $schedules = Schedule::with([
            'section', 'subject', 'faculty', 'room', 'department'
        ])->where('term_id', $termId)->get();

        return response()->json($schedules);
    }

    // Get schedules by section
    public function bySection($sectionId)
    {
        $schedules = Schedule::with([
            'subject', 'faculty', 'room'
        ])->where('section_id', $sectionId)->get();

        return response()->json($schedules);
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
}

class AtomicScheduleValidationException extends \RuntimeException
{
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Atomic schedule validation failed.');
    }
}
