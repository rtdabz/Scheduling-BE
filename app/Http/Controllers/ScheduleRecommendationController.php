<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleRecommendation;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Services\Scheduling\CSPSolver;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\SchedulingPolicy;
use App\Services\SystemNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ScheduleRecommendationController extends Controller
{
    public function __construct(
        private readonly CSPSolver $cspSolver,
        private readonly RuleEngine $ruleEngine,
        private readonly SystemNotificationService $notifications,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'sometimes|integer|exists:sections,id',
            'status' => 'sometimes|in:pending,accepted,rejected',
        ]);

        $recommendations = ScheduleRecommendation::with(['section', 'term', 'department', 'requester'])
            ->when(isset($validated['section_id']), fn ($query) => $query->where('section_id', $validated['section_id']))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->latest()
            ->get();

        return response()->json($recommendations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:25',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1',
        ]);

        try {
            $solutions = $this->cspSolver->solveRankedFromSchema($validated);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);
        $user = $request->user();

        $recommendations = DB::transaction(function () use ($solutions, $section, $validated, $user) {
            $created = [];

            foreach ($solutions as $solution) {
                $recommendation = ScheduleRecommendation::create([
                    'term_id' => (int) $section->term_id,
                    'section_id' => (int) $section->id,
                    'department_id' => (int) $section->department_id,
                    'requested_by' => $user?->id,
                    'rank' => (int) $solution['rank'],
                    'score' => (int) $solution['score'],
                    'status' => 'pending',
                    'input_payload' => $validated,
                    'recommended_schedules' => $solution['schedules'],
                ]);

                $this->recordAudit(
                    action: 'recommendation_generated',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'rank' => $solution['rank'],
                        'score' => $solution['score'],
                        'schedule_count' => count($solution['schedules']),
                    ],
                );

                $created[] = $recommendation->load(['section', 'term', 'department', 'requester']);
            }

            return $created;
        });

        if ($recommendations !== []) {
            $section->loadMissing(['department', 'term']);
            $this->notifications->notifyRoles(
                ['secretary', 'program_head', 'dean'],
                'schedule_generation_completed',
                'Schedule generation completed',
                $this->notifications->departmentWorkflowMessage(
                    'generated schedule recommendations for',
                    $section->department,
                    $section->term,
                    $user,
                    count($recommendations),
                ),
                $user,
                (int) $section->department_id,
                (int) $section->term_id,
                null,
                [
                    'section_id' => $section->id,
                    'recommendations_generated' => count($recommendations),
                    'search_limit_reached' => $this->cspSolver->searchLimitReached(),
                    'iterations_used' => $this->cspSolver->iterationsUsed(),
                ],
            );
        }

        return response()->json([
            'message' => $recommendations === []
                ? 'No recommendations found that satisfy the scheduling constraints.'
                : 'Schedule recommendations generated successfully.',
            'search_limit_reached' => $this->cspSolver->searchLimitReached(),
            'iterations_used' => $this->cspSolver->iterationsUsed(),
            'recommendations' => $recommendations,
        ], 201);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
        ]);

        try {
            $solutions = $this->cspSolver->solveRankedFromSchema($validated);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => $solutions === []
                ? 'No recommendations found that satisfy the scheduling constraints.'
                : 'Schedule recommendations generated successfully.',
            'search_limit_reached' => $this->cspSolver->searchLimitReached(),
            'iterations_used' => $this->cspSolver->iterationsUsed(),
            'recommendations' => $solutions,
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'selected_rank' => 'required|integer|min:1|max:5',
        ]);

        $solverInput = $validated;
        $selectedRank = (int) $solverInput['selected_rank'];
        unset($solverInput['selected_rank']);

        try {
            $solutions = $this->cspSolver->solveRankedFromSchema($solverInput);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $selectedSolution = collect($solutions)->first(
            static fn (array $solution): bool => (int) $solution['rank'] === $selectedRank,
        );

        if ($selectedSolution === null) {
            return response()->json([
                'message' => 'The selected recommendation is no longer available. Please refresh the recommendations.',
            ], 422);
        }

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($solverInput['section_id']);
        $user = $request->user();

        $recommendation = DB::transaction(function () use ($selectedSolution, $section, $solverInput, $user) {
            $recommendation = ScheduleRecommendation::create([
                'term_id' => (int) $section->term_id,
                'section_id' => (int) $section->id,
                'department_id' => (int) $section->department_id,
                'requested_by' => $user?->id,
                'rank' => (int) $selectedSolution['rank'],
                'score' => (int) $selectedSolution['score'],
                'status' => 'pending',
                'input_payload' => $solverInput,
                'recommended_schedules' => $selectedSolution['schedules'],
            ]);

            $this->recordAudit(
                action: 'recommendation_selected',
                userId: $user?->id,
                recommendation: $recommendation,
                metadata: [
                    'rank' => $selectedSolution['rank'],
                    'score' => $selectedSolution['score'],
                    'schedule_count' => count($selectedSolution['schedules']),
                ],
            );

            return $recommendation->load(['section', 'term', 'department', 'requester']);
        });

        return response()->json([
            'message' => 'Schedule recommendation selected successfully.',
            'recommendation' => $recommendation,
        ], 201);
    }

    public function show(ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        return response()->json($scheduleRecommendation->load([
            'section',
            'term',
            'department',
            'requester',
            'accepter',
            'rejecter',
        ]));
    }

    public function review(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        $user = $request->user();

        $recommendation = DB::transaction(function () use ($scheduleRecommendation, $user) {
            /** @var ScheduleRecommendation $recommendation */
            $recommendation = ScheduleRecommendation::query()
                ->whereKey($scheduleRecommendation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->recordAudit(
                action: 'recommendation_reviewed',
                userId: $user?->id,
                recommendation: $recommendation,
                metadata: [
                    'status' => $recommendation->status,
                ],
            );

            return $recommendation->fresh([
                'section',
                'term',
                'department',
                'requester',
                'accepter',
                'rejecter',
            ]);
        });

        return response()->json([
            'message' => 'Recommendation review recorded successfully.',
            'recommendation' => $recommendation,
        ]);
    }

    public function accept(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        $user = $request->user();

        try {
            $result = DB::transaction(function () use ($scheduleRecommendation, $user) {
                /** @var ScheduleRecommendation $recommendation */
                $recommendation = ScheduleRecommendation::query()
                    ->whereKey($scheduleRecommendation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($recommendation->status !== 'pending') {
                    throw new InvalidArgumentException('Only pending recommendations can be accepted.');
                }

                $rows = $this->normalizeRecommendedSchedules($recommendation);
                $this->validateBatchConflicts($rows);

                $violations = [];
                foreach ($rows as $index => $row) {
                    $rowViolations = $this->ruleEngine->validate($row);
                    foreach ($rowViolations as $violation) {
                        $violation['recommendation_row'] = $index;
                        $violations[] = $violation;
                    }
                }

                if ($violations !== []) {
                    throw new RecommendationConflictException($violations);
                }

                $createdSchedules = [];
                foreach ($rows as $row) {
                    $createdSchedules[] = Schedule::create($row)->load([
                        'term',
                        'section',
                        'subject',
                        'faculty',
                        'room',
                        'department',
                    ]);
                }

                $recommendation->update([
                    'status' => 'accepted',
                    'accepted_by' => $user?->id,
                    'accepted_at' => now(),
                ]);

                $this->recordAudit(
                    action: 'recommendation_accepted',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'created_schedule_ids' => array_map(
                            static fn (Schedule $schedule): int => (int) $schedule->id,
                            $createdSchedules,
                        ),
                    ],
                );

                return [
                    'recommendation' => $recommendation->fresh([
                        'section',
                        'term',
                        'department',
                        'requester',
                        'accepter',
                    ]),
                    'schedules' => $createdSchedules,
                ];
            });
        } catch (RecommendationConflictException $exception) {
            return response()->json([
                'message' => 'Recommendation conflicts with existing or pending schedule entries.',
                'violations' => $exception->violations,
            ], 422);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Recommendation accepted and schedules created successfully.',
            'recommendation' => $result['recommendation'],
            'schedules' => $result['schedules'],
        ]);
    }

    public function reject(Request $request, ScheduleRecommendation $scheduleRecommendation): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        try {
            $recommendation = DB::transaction(function () use ($scheduleRecommendation, $validated, $user) {
                /** @var ScheduleRecommendation $recommendation */
                $recommendation = ScheduleRecommendation::query()
                    ->whereKey($scheduleRecommendation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($recommendation->status !== 'pending') {
                    throw new InvalidArgumentException('Only pending recommendations can be rejected.');
                }

                $recommendation->update([
                    'status' => 'rejected',
                    'rejected_by' => $user?->id,
                    'rejected_at' => now(),
                    'rejection_reason' => $validated['reason'] ?? null,
                ]);

                $this->recordAudit(
                    action: 'recommendation_rejected',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'reason' => $validated['reason'] ?? null,
                    ],
                );

                return $recommendation->fresh([
                    'section',
                    'term',
                    'department',
                    'requester',
                    'rejecter',
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Recommendation rejected successfully.',
            'recommendation' => $recommendation,
        ]);
    }

    private function normalizeRecommendedSchedules(ScheduleRecommendation $recommendation): array
    {
        $rows = $recommendation->recommended_schedules;

        if (!is_array($rows) || $rows === []) {
            throw new RuntimeException('Recommendation does not contain schedule rows.');
        }

        return array_map(function (array $row) use ($recommendation): array {
            return [
                'term_id' => (int) ($row['term_id'] ?? $recommendation->term_id),
                'section_id' => (int) ($row['section_id'] ?? $recommendation->section_id),
                'subject_id' => (int) $row['subject_id'],
                'faculty_id' => isset($row['faculty_id']) ? (int) $row['faculty_id'] : null,
                'room_id' => (int) $row['room_id'],
                'department_id' => (int) ($row['department_id'] ?? $recommendation->department_id),
                'day' => (string) $row['day'],
                'start_time' => SchedulingPolicy::normalizeTime((string) $row['start_time']),
                'end_time' => SchedulingPolicy::normalizeTime((string) $row['end_time']),
                'mode' => (string) ($row['mode'] ?? 'on-site'),
                'is_hybrid' => (bool) ($row['is_hybrid'] ?? false),
                'preferred_pattern' => $row['preferred_pattern'] ?? null,
                'status' => (string) ($row['status'] ?? 'draft'),
            ];
        }, $rows);
    }

    private function validateBatchConflicts(array $rows): void
    {
        $violations = [];

        foreach ($rows as $leftIndex => $left) {
            foreach ($rows as $rightIndex => $right) {
                if ($leftIndex >= $rightIndex) {
                    continue;
                }

                if ($left['term_id'] !== $right['term_id'] || $left['day'] !== $right['day']) {
                    continue;
                }

                if (!$this->timesOverlap($left['start_time'], $left['end_time'], $right['start_time'], $right['end_time'])) {
                    continue;
                }

                if ($left['section_id'] === $right['section_id']) {
                    $violations[] = $this->batchViolation('section_conflict', $leftIndex, $rightIndex);
                }

                if ($left['room_id'] === $right['room_id']) {
                    $violations[] = $this->batchViolation('room_conflict', $leftIndex, $rightIndex);
                }

                if (
                    !empty($left['faculty_id'])
                    && !empty($right['faculty_id'])
                    && $left['faculty_id'] === $right['faculty_id']
                ) {
                    $violations[] = $this->batchViolation('faculty_conflict', $leftIndex, $rightIndex);
                }
            }
        }

        foreach ($rows as $index => $row) {
            $duplicateExists = Schedule::query()
                ->where('term_id', $row['term_id'])
                ->where('section_id', $row['section_id'])
                ->where('subject_id', $row['subject_id'])
                ->exists();

            if ($duplicateExists) {
                $violations[] = [
                    'rule' => 'duplicate_section_subject',
                    'message' => 'This section already has a schedule for one of the recommended subjects.',
                    'recommendation_row' => $index,
                ];
            }
        }

        if ($violations !== []) {
            throw new RecommendationConflictException($violations);
        }
    }

    private function timesOverlap(string $leftStart, string $leftEnd, string $rightStart, string $rightEnd): bool
    {
        return $leftStart < $rightEnd && $rightStart < $leftEnd;
    }

    private function batchViolation(string $rule, int $leftIndex, int $rightIndex): array
    {
        return [
            'rule' => $rule,
            'message' => "Recommended rows {$leftIndex} and {$rightIndex} overlap before they can be accepted.",
            'recommendation_rows' => [$leftIndex, $rightIndex],
        ];
    }

    private function recordAudit(
        string $action,
        ?int $userId,
        ScheduleRecommendation $recommendation,
        array $metadata = [],
    ): void {
        SchedulingAuditLog::create([
            'user_id' => $userId,
            'schedule_recommendation_id' => $recommendation->id,
            'term_id' => $recommendation->term_id,
            'section_id' => $recommendation->section_id,
            'department_id' => $recommendation->department_id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}

class RecommendationConflictException extends RuntimeException
{
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Recommendation conflict.');
    }
}
