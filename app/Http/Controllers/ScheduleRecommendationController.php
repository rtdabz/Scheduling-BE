<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleRecommendation;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Curriculum;
use App\Services\Scheduling\CSPSolver;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\SchedulingPolicy;
use App\Services\Scheduling\SplitScheduleService;
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
        private readonly SplitScheduleService $splitScheduleService,
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

    /**
     * Find conflict-free placements for a single split-session block.
     *
     * POST /api/schedule-recommendations/recommend-split
     *
     * The Rule Engine validates every candidate; only conflict-free options
     * are returned. If no valid placement exists anywhere within operating
     * hours, status='no_solution' is returned instead of an error.
     */
    public function recommendSplit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'term_id'                => 'required|integer|exists:terms,id',
            'section_id'             => 'required|integer|exists:sections,id',
            'course_id'              => 'required|integer|exists:courses,id',
            'department_id'          => 'required|integer|exists:departments,id',
            'duration_slots'         => 'required|integer|min:1|max:24',
            'room_id'                => 'nullable|integer|exists:rooms,id',
            'mode'                   => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'faculty_id'             => 'nullable|integer|exists:faculties,id',
            'delete_ids'             => 'sometimes|array',
            'delete_ids.*'           => 'integer|exists:schedules,id',
            'max_solutions'          => 'sometimes|integer|min:1|max:10',
            'timeout_seconds'        => 'sometimes|numeric|min:0.5|max:15',
            'meeting_type'           => 'nullable|in:lecture,laboratory',
            'preferred_day'          => 'nullable|string',
            'preferred_start_time'   => 'nullable|string',
        ]);

        try {
            $result = $this->splitScheduleService->recommend(
                termId:             (int) $validated['term_id'],
                sectionId:          (int) $validated['section_id'],
                courseId:           (int) $validated['course_id'],
                departmentId:       (int) $validated['department_id'],
                durationSlots:      (int) $validated['duration_slots'],
                roomId:             isset($validated['room_id']) ? (int) $validated['room_id'] : null,
                mode:               $validated['mode'] ?? 'on-site',
                facultyId:          isset($validated['faculty_id']) ? (int) $validated['faculty_id'] : null,
                deleteIds:          array_map('intval', $validated['delete_ids'] ?? []),
                maxResults:         (int) ($validated['max_solutions'] ?? 5),
                timeoutSeconds:     (float) ($validated['timeout_seconds'] ?? 5.0),
                meetingType:        $validated['meeting_type'] ?? null,
                preferredDay:       $validated['preferred_day'] ?? null,
                preferredStartTime: $validated['preferred_start_time'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['status'] === 'no_solution') {
            return response()->json([
                'status'  => 'no_solution',
                'message' => 'No conflict-free time slot found for this split session. '
                    . 'All available times on all days are occupied. '
                    . 'Try a different room, delivery mode, or reduce other scheduled sessions.',
                'recommendations' => [],
            ]);
        }

        return response()->json([
            'status'          => 'ok',
            'message'         => 'Conflict-free placements found for this split session.',
            'recommendations' => $result['recommendations'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:25',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        // Clear previous generated schedules and cached solutions
        Schedule::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->whereIn('status', ['draft', 'completed'])
            ->delete();

        ScheduleRecommendation::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->delete();

        try {
            $validated['course_ids'] = $this->resolveCourseIds($section, $validated['course_ids'] ?? null);
            $solutions = $this->cspSolver->solveRankedFromSchema($validated);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

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
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        // Clear previous generated schedules and cached solutions
        Schedule::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->whereIn('status', ['draft', 'completed'])
            ->delete();

        ScheduleRecommendation::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->delete();

        try {
            $validated['course_ids'] = $this->resolveCourseIds($section, $validated['course_ids'] ?? null);
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
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_solutions' => 'sometimes|integer|min:1|max:5',
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1|max:5',
            'selected_rank' => 'required|integer|min:1|max:5',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        $solverInput = $validated;
        $selectedRank = (int) $solverInput['selected_rank'];
        unset($solverInput['selected_rank']);

        try {
            $solverInput['course_ids'] = $this->resolveCourseIds($section, $solverInput['course_ids'] ?? null);
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

                $courseIdsToCreate = array_values(array_unique(array_map(
                    static fn (array $r): int => (int) $r['course_id'],
                    $rows,
                )));

                Schedule::query()
                    ->where('term_id', $rows[0]['term_id'])
                    ->where('section_id', $rows[0]['section_id'])
                    ->whereIn('course_id', $courseIdsToCreate)
                    ->whereIn('status', ['draft', 'completed'])
                    ->delete();

                $createdIds = [];
                foreach ($rows as $row) {
                    $s = Schedule::create($row);
                    $createdIds[] = $s->id;
                }

                $createdSchedules = Schedule::query()
                    ->whereIn('id', $createdIds)
                    ->with([
                        'term',
                        'section',
                        'course',
                        'faculty',
                        'room',
                        'department',
                    ])
                    ->get();

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
                        'created_schedule_ids' => $createdSchedules->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
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
            $courseId = (int) ($row['course_id'] ?? $row['subject_id']);

            return [
                'term_id'           => (int) ($row['term_id'] ?? $recommendation->term_id),
                'section_id'        => (int) ($row['section_id'] ?? $recommendation->section_id),
                'course_id'         => $courseId,
                'faculty_id'        => isset($row['faculty_id']) ? (int) $row['faculty_id'] : null,
                'room_id'           => isset($row['room_id']) && $row['room_id'] !== null ? (int) $row['room_id'] : null,
                'department_id'     => (int) ($row['department_id'] ?? $recommendation->department_id),
                'day'               => (string) $row['day'],
                'start_time'        => SchedulingPolicy::normalizeTime((string) $row['start_time']),
                'end_time'          => SchedulingPolicy::normalizeTime((string) $row['end_time']),
                'mode'              => (string) ($row['mode'] ?? 'on-site'),
                'is_hybrid'         => (bool) ($row['is_hybrid'] ?? false),
                'preferred_pattern' => $row['preferred_pattern'] ?? null,
                'status'            => (string) ($row['status'] ?? 'draft'),
                'split_group_id'    => $row['split_group_id'] ?? null,
                'meeting_type'      => $row['meeting_type'] ?? null,
                'meeting_index'     => isset($row['meeting_index']) ? (int) $row['meeting_index'] : null,
            ];
        }, $rows);

    }

        /**
     * Resolve which courses to schedule for a section. If the caller
     * explicitly supplied course_ids, use those (manual override still
     * allowed). Otherwise, derive the list from the section's department's
     * ACTIVE curriculum, filtered to the section's year_level and semester —
     * Curriculum is the source of truth for what should be scheduled.
     */
    private function resolveCourseIds(Sections $section, ?array $providedCourseIds): array
    {
        if (!empty($providedCourseIds)) {
            $curriculum = Curriculum::where('department_id', $section->department_id)
                ->where('status', 'active')
                ->first();

            if ($curriculum) {
                $validPivotIds = $curriculum->courses()
                    ->whereIn('courses.id', $providedCourseIds)
                    ->wherePivot('year_level', (int) $section->year_level)
                    ->wherePivot('semester', $this->mapSemesterToInt($section->semester))
                    ->pluck('courses.id')
                    ->toArray();

                if (!empty($validPivotIds)) {
                    return $validPivotIds;
                }
            }

            $validProvidedIds = \App\Models\Course::query()
                ->whereIn('id', $providedCourseIds)
                ->where('status', 'active')
                ->where('year_level', (string) $section->year_level)
                ->where('semester', (string) $section->semester)
                ->pluck('id')
                ->toArray();

            if (!empty($validProvidedIds)) {
                return $validProvidedIds;
            }
        }

        $curriculum = Curriculum::where('department_id', $section->department_id)
            ->where('status', 'active')
            ->first();

        if ($curriculum) {
            $courseIds = $curriculum->courses()
                ->wherePivot('year_level', (int) $section->year_level)
                ->wherePivot('semester', $this->mapSemesterToInt($section->semester))
                ->pluck('courses.id')
                ->toArray();

            if (!empty($courseIds)) {
                return $courseIds;
            }
        }

        // Fallback: search courses table directly for courses matching section department/year_level/semester
        $fallbackCourseIds = \App\Models\Course::query()
            ->where('status', 'active')
            ->where(function ($q) use ($section) {
                $q->whereNull('department_id')
                  ->orWhere('department_id', $section->department_id);
            })
            ->where('year_level', (string) $section->year_level)
            ->where('semester', (string) $section->semester)
            ->pluck('id')
            ->toArray();

        if (!empty($fallbackCourseIds)) {
            return $fallbackCourseIds;
        }

        if (!$curriculum) {
            throw new InvalidArgumentException(
                'No active curriculum found for this department. Activate a curriculum before generating a schedule.'
            );
        }

        throw new InvalidArgumentException(
            "The active curriculum ({$curriculum->name}) has no courses defined for Year {$section->year_level}, {$section->semester} semester."
        );
    }

    private function mapSemesterToInt(string $semester): int
    {
        return match ($semester) {
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
            default => throw new InvalidArgumentException("Unrecognized semester '{$semester}'."),
        };
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

                if (
                    $left['room_id'] !== null &&
                    $right['room_id'] !== null &&
                    $left['room_id'] === $right['room_id']
                ) {
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
                ->where('course_id', $row['course_id'])
                ->whereNotIn('status', ['draft', 'completed'])
                ->exists();

            if ($duplicateExists) {
                $violations[] = [
                    'rule' => 'duplicate_section_course',
                    'message' => 'This section already has an approved/finalized schedule for one of the recommended courses.',
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

    public function autoGenerateAndApply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|integer|exists:sections,id',
            'course_ids' => 'sometimes|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
            'mode' => SchedulingPolicy::allowedDeliveryModesRule('sometimes'),
            'is_hybrid' => 'sometimes|boolean',
            'preferred_patterns' => 'sometimes|array',
            'preferred_patterns.*' => ['nullable', 'string', 'max:20', fn ($attribute, $value, $fail) => SchedulingPolicy::isValidPreferredPattern($value) ? null : $fail('The preferred pattern is not supported.')],
            'max_iterations' => 'sometimes|integer|min:1',
            'timeout_seconds' => 'sometimes|numeric|min:0.1',
            'seed' => 'sometimes|integer',
        ]);

        /** @var Sections $section */
        $section = Sections::query()->findOrFail($validated['section_id']);

        // Clear previous generated schedules and cached solutions
        Schedule::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->whereIn('status', ['draft', 'completed'])
            ->delete();

        ScheduleRecommendation::where('section_id', $section->id)
            ->where('term_id', $section->term_id)
            ->delete();

        try {
            $resolvedCourseIds = $this->resolveCourseIds($section, $validated['course_ids'] ?? null);
            $validated['course_ids'] = $resolvedCourseIds;
            $validated['max_solutions'] = 1;

            $solutions = $this->cspSolver->solveRankedFromSchema($validated);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (empty($solutions)) {
            return response()->json([
                'message' => 'No valid schedule could be generated that satisfies all constraints for this section.',
            ], 422);
        }

        $bestSolution = $solutions[0];
        $user = $request->user();

        try {
            $createdSchedules = DB::transaction(function () use ($bestSolution, $section, $validated, $user) {
                $recommendation = ScheduleRecommendation::create([
                    'term_id' => (int) $section->term_id,
                    'section_id' => (int) $section->id,
                    'department_id' => (int) $section->department_id,
                    'requested_by' => $user?->id,
                    'accepted_by' => $user?->id,
                    'accepted_at' => now(),
                    'rank' => (int) $bestSolution['rank'],
                    'score' => (int) $bestSolution['score'],
                    'status' => 'accepted',
                    'input_payload' => $validated,
                    'recommended_schedules' => $bestSolution['schedules'],
                ]);

                $rows = $this->normalizeRecommendedSchedules($recommendation);
                $this->validateBatchConflicts($rows);

                $courseIdsToCreate = array_values(array_unique(array_map(
                    static fn (array $r): int => (int) $r['course_id'],
                    $rows,
                )));

                Schedule::query()
                    ->where('term_id', $rows[0]['term_id'])
                    ->where('section_id', $rows[0]['section_id'])
                    ->whereIn('course_id', $courseIdsToCreate)
                    ->whereIn('status', ['draft', 'completed'])
                    ->delete();

                $createdIds = [];
                foreach ($rows as $row) {
                    $s = Schedule::create($row);
                    $createdIds[] = $s->id;
                }

                $schedules = Schedule::query()
                    ->whereIn('id', $createdIds)
                    ->with([
                        'term',
                        'section',
                        'course',
                        'faculty',
                        'room',
                        'department',
                    ])
                    ->get();

                $this->recordAudit(
                    action: 'recommendation_auto_applied',
                    userId: $user?->id,
                    recommendation: $recommendation,
                    metadata: [
                        'created_schedule_ids' => $schedules->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                    ],
                );

                return $schedules;
            });
        } catch (RecommendationConflictException $exception) {
            return response()->json([
                'message' => 'Generated schedule conflicts with existing schedules.',
                'violations' => $exception->violations,
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Schedule generated and placed into Timetable Grid successfully.',
            'schedules' => $createdSchedules,
        ]);
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
