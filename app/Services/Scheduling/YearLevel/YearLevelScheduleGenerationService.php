<?php

namespace App\Services\Scheduling\YearLevel;

use App\Exceptions\ScheduleGenerationPreflightException;
use App\Exceptions\YearLevelGenerationException;
use App\Models\Course;
use App\Models\Rooms;
use App\Models\Sections;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Solver\YearLevelSchedulingSolver;
use App\Services\Scheduling\Generation\ScheduleGenerationPreflightService;
use App\Services\Scheduling\Generation\ScheduleQualityEvaluator;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Support\GenerationCancellationToken;
use App\Services\Scheduling\Support\SchedulingMetricsReporter;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Support\Collection;
use RuntimeException;

class YearLevelScheduleGenerationService
{
    private const SECTION_ATTEMPTS = 2;

    private const SPLIT_HEAVY_SECTION_ATTEMPTS = 2;

    private const SPLIT_HEAVY_COURSE_THRESHOLD = 3;

    private const SECTION_SOLUTIONS_PER_ATTEMPT = 3;

    /**
     * Step budget of a section's first search; each restart doubles it. A
     * depth-first search that commits to a poor early placement can spend its
     * whole budget under it: one BSIT 3E search ran 24 seconds and ~190,000
     * steps without a result, while a different seed placed the same section
     * in 56 steps. Short, reseeded restarts leave such a dead end quickly, and
     * the doubling still gives a genuinely hard section long searches.
     */
    private const RESTART_INITIAL_ITERATIONS = 2000;

    /** Seed stride between restarts, apart from the attempt stride (7919). */
    private const RESTART_SEED_STRIDE = 104729;

    /**
     * Per-attempt cap for a section while the previous section still has
     * other arrangements to try. An arrangement can leave the next section no
     * timetable at all -- BSIT 3D taking the last Monday/Wednesday lecture-room
     * pairs BSIT 3E needed -- and the search cannot prove that quickly; trying
     * the previous section's next arrangement is far cheaper than spending the
     * full attempt on it. The last arrangement keeps the full budget.
     */
    private const SIBLING_ATTEMPT_SECONDS = 6.0;

    private const MAX_SECTION_ORDER_CANDIDATES = 2;

    private const MAX_COMPLETE_CANDIDATES_PER_ORDER = 6;

    private const PREVIEW_TIME_BUDGET_SECONDS = 135.0;

    private const RESERVED_SECONDS_PER_REMAINING_SECTION = 4.0;

    /**
     * Share of the run budget the unmodified configuration gets before the retry
     * ladder starts. Grinding the same over-constrained ordering for the whole
     * budget is what the retry ladder exists to replace, so the remainder is
     * reserved for strategies that change the shape of the search.
     */
    private const BASELINE_BUDGET_SHARE = 0.6;

    /** Wall-clock wording for the hard teaching windows, for failure messages. */
    /** A retry below this is not worth starting. */
    private const MIN_RETRY_SECONDS = 8.0;

    private const MAX_RETRY_STRATEGIES = 4;

    /**
     * Courses in the current run, kept so the deep recursion can name the
     * failing course without re-querying at every backtrack.
     *
     * @var Collection<int, Course>
     */
    private Collection $loadedCourses;

    /** Candidate rows currently selected in the recursive in-memory search. */
    private array $tentativeSchedules = [];

    private float $metricsStartedAt = 0.0;

    /** @var array<string, int> */
    private array $aggregateMetrics = [];

    /** @var array<string, int> */
    private array $aggregatePrunedByConstraint = [];

    /** @var array<string, int> */
    private array $aggregateFallbackUsage = [];

    /** The physical-only search stopped before proving infeasibility. */
    private bool $physicalSearchIncomplete = false;

    private ?SchedulingSnapshot $generationSnapshot = null;

    /** Cooperative cancellation for the run in progress. */
    private GenerationCancellationToken $cancellation;

    public function __construct(
        private readonly YearLevelSchedulingSolver $solver,
        private readonly ScheduleQualityEvaluator $evaluator,
        private readonly SchedulingSnapshotRepository $snapshots,
        private ?YearLevelFeasibilityService $feasibility = null,
        private ?YearLevelGenerationDiagnostics $diagnostics = null,
        private ?YearLevelRetryStrategyPlanner $planner = null,
        private ?ScheduleRequirementBuilderResolver $requirementBuilders = null,
        private ?ScheduleGenerationPreflightService $preflight = null,
        private ?SchedulingMetricsReporter $metricsReporter = null,
    ) {
        $this->loadedCourses = collect();
        $this->cancellation = GenerationCancellationToken::none();
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return array<string, mixed>
     *
     * @throws YearLevelGenerationException when no valid timetable can be produced
     */
    public function preview(
        array $sections,
        array $configsBySectionId,
        ?GenerationCancellationToken $cancellation = null,
    ): array {
        $this->resetMetrics();
        $this->cancellation = $cancellation ?? GenerationCancellationToken::none();

        if ($sections === []) {
            throw new RuntimeException('No active sections were found for the selected year level.');
        }

        $this->solver->beginGenerationContext();

        $courses = $this->loadedCourses = $this->loadCourses($configsBySectionId);
        $configsBySectionId = $this->decorateConfigs($configsBySectionId, $courses);
        // Capture every course requested by the section configurations. Do
        // not derive this list from the keyed load result: queued payloads
        // may normalize collection keys differently while preserving the
        // authoritative course_ids arrays.
        $snapshotCourseIds = [];
        foreach ($configsBySectionId as $config) {
            foreach (($config['course_ids'] ?? []) as $courseId) {
                $id = (int) $courseId;
                if ($id > 0) {
                    $snapshotCourseIds[$id] = $id;
                }
            }
        }

        $this->generationSnapshot = $this->snapshots->capture(
            semesterId: (int) $sections[0]->semester_id,
            departmentId: (int) $sections[0]->department_id,
            sectionIds: array_map(static fn (Sections $section): int => (int) $section->id, $sections),
            courseIds: array_values($snapshotCourseIds),
        );

        // Feasibility pre-check: refuse only provable shortfalls, before spending
        // two minutes searching for something that cannot exist.
        $blocking = $this->feasibility()->check($sections, $configsBySectionId);
        if ($blocking !== []) {
            throw new YearLevelGenerationException(
                $this->diagnostics()->feasibilityMessage($blocking),
                YearLevelGenerationException::STAGE_FEASIBILITY,
                blockingConstraints: $blocking,
                recommendations: [
                    ...$this->diagnostics()->feasibilityRecommendations($blocking),
                ],
                generationMetrics: $this->reportedMetrics([]),
            );
        }

        $startedAt = microtime(true);
        $hardDeadline = $startedAt + self::PREVIEW_TIME_BUDGET_SECONDS;
        $retryPossible = count($sections) > 1 || $this->hasRelaxablePreferences($configsBySectionId);
        $baselineDeadline = $retryPossible
            ? $startedAt + (self::PREVIEW_TIME_BUDGET_SECONDS * self::BASELINE_BUDGET_SHARE)
            : $hardDeadline;

        $attempts = [];
        $failures = [];

        $patternFailure = $this->preflightPatternFeasibility($sections, $configsBySectionId, $courses);
        if ($patternFailure !== null) {
            // A fixed pattern with no section-level candidate at all: skip the
            // baseline search and go straight to the retry ladder, which is
            // where alternative patterns live.
            $failures[] = $patternFailure;
            $attempts[] = $this->attemptRecord(
                'preflight_pattern',
                'Fixed pattern pre-check',
                'failed',
                $patternFailure,
                'The configured MW/TTh pattern has no valid placement for this section on its own.',
            );
        } else {
            $baselineFailures = [];
            $candidate = $this->generateBestCandidate($sections, $configsBySectionId, $baselineDeadline, 0, 0, $baselineFailures);
            $attempts[] = $this->attemptRecord(
                'baseline',
                'Original configuration',
                $candidate !== null ? 'succeeded' : 'failed',
                $baselineFailures[0] ?? null,
                'Generate with exactly the configuration you selected.',
            );

            if ($candidate !== null) {
                return $this->decorateResult($candidate, null, $attempts, $configsBySectionId, $sections);
            }

            $failures = [...$failures, ...$baselineFailures];
        }

        $bottleneck = $this->diagnostics()->detectBottleneck($failures, $courses);

        $strategies = array_slice(
            $this->planner()->plan($sections, $configsBySectionId, $courses, $bottleneck),
            0,
            self::MAX_RETRY_STRATEGIES,
        );

        $pending = count($strategies);

        foreach ($strategies as $strategy) {
            $this->cancellation->abortIfCancelled();
            $pending--;
            $key = (string) ($strategy['key'] ?? 'retry');
            $label = (string) ($strategy['label'] ?? 'Retry');
            $description = (string) ($strategy['description'] ?? '');

            $remainingSeconds = $hardDeadline - microtime(true);
            if ($remainingSeconds < self::MIN_RETRY_SECONDS) {
                $attempts[] = $this->attemptRecord($key, $label, 'skipped_no_time', null, $description);

                continue;
            }

            $retryConfigs = $this->applyAdjustments(
                $sections,
                $configsBySectionId,
                array_values((array) ($strategy['adjustments'] ?? [])),
                $courses,
            );
            if ($retryConfigs === null) {
                $attempts[] = $this->attemptRecord($key, $label, 'not_applicable', null, $description);

                continue;
            }

            $strategyDeadline = min(
                $hardDeadline,
                microtime(true) + max(self::MIN_RETRY_SECONDS, $remainingSeconds / max(1, $pending + 1)),
            );
            $retryFailures = [];
            $candidate = $this->generateBestCandidate(
                $sections,
                $retryConfigs,
                $strategyDeadline,
                (int) ($strategy['order_offset'] ?? 0),
                (int) ($strategy['seed_offset'] ?? 0),
                $retryFailures,
            );
            $attempts[] = $this->attemptRecord(
                $key,
                $label,
                $candidate !== null ? 'succeeded' : 'failed',
                $retryFailures[0] ?? null,
                $description,
            );

            if ($candidate !== null) {
                return $this->decorateResult($candidate, $strategy, $attempts, $configsBySectionId, $sections);
            }

            $failures = [...$failures, ...$retryFailures];
        }

        $bottleneck = $this->diagnostics()->detectBottleneck($failures, $courses) ?? $bottleneck;
        $recommendations = $this->diagnostics()->searchRecommendations(
            $bottleneck,
            $strategies,
            $courses,
            $configsBySectionId,
        );

        throw new YearLevelGenerationException(
            $this->diagnostics()->searchMessage($bottleneck, $attempts),
            YearLevelGenerationException::STAGE_SEARCH,
            bottleneck: $bottleneck,
            attempts: $attempts,
            recommendations: $recommendations,
            generationMetrics: $this->reportedMetrics($attempts),
        );
    }

    /**
     * Best complete candidate across the section orderings this attempt may use.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  list<array<string, mixed>>  $failures
     * @return array<string, mixed>|null
     */
    private function generateBestCandidate(
        array $sections,
        array $configsBySectionId,
        float $deadline,
        int $orderOffset,
        int $seedOffset,
        array &$failures,
    ): ?array {
        foreach ($this->candidateOrders($sections, $configsBySectionId, $orderOffset) as $order) {
            $this->cancellation->abortIfCancelled();
            if (microtime(true) >= $deadline) {
                break;
            }

            $failure = null;
            $candidate = $this->generateForOrder($order, $configsBySectionId, $deadline, $seedOffset, $failure);
            if ($candidate !== null) {
                // Alternative orderings exist to recover from an ordering that
                // could not be completed, not to shop for a marginally better
                // score. Each one costs a full set of section solves, so
                // continuing past the first success doubled the wall time of
                // every run that was going to succeed anyway. generateForOrder
                // has already ranked every complete candidate this ordering
                // produced, so the quality choice is still made - just within
                // the ordering the heuristic put first.
                return $candidate;
            }

            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $strategy
     * @param  list<array<string, mixed>>  $attempts
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  list<Sections>  $sections
     * @return array<string, mixed>
     */
    private function decorateResult(
        array $candidate,
        ?array $strategy,
        array $attempts,
        array $configsBySectionId,
        array $sections,
    ): array {
        $splitFallbacks = $this->detectSplitSessionFallbacks(
            $candidate['schedules'] ?? [],
            $configsBySectionId,
            $sections,
        );
        $existingAdjustments = $strategy === null
            ? []
            : array_values((array) ($strategy['adjustments'] ?? []));

        $candidate['generation_attempts'] = $attempts;
        $candidate['applied_strategy'] = $strategy === null && $splitFallbacks === [] ? null : [
            'key' => (string) ($strategy['key'] ?? ''),
            'label' => (string) ($strategy['label'] ?? 'Scheduling preference adjusted for feasibility'),
            'description' => (string) ($strategy['description']
                ?? 'The selected configuration found no complete timetable, so the generator applied a reported preference adjustment while preserving all hard constraints.'),
            'impact' => (string) ($strategy['impact'] ?? 'medium'),
        ];
        $candidate['applied_adjustments'] = [...$existingAdjustments, ...$splitFallbacks];
        $candidate['generation_changes'] = (new YearLevelGenerationChangeReport)->build(
            $strategy,
            $splitFallbacks,
            $candidate['schedules'] ?? [],
            collect($sections)->mapWithKeys(static fn (Sections $section): array => [(int) $section->id => (string) $section->section_name])->all(),
            $this->loadedCourses->mapWithKeys(static fn ($course): array => [(int) $course->id => (string) $course->course_code])->all(),
        );
        $candidate['recommendations'] = $this->generationRecommendations(
            $configsBySectionId,
            $sections,
        );
        $candidate['generation_metrics'] = $this->reportedMetrics($attempts);

        return $candidate;
    }

    /**
     * Report only actual flexible-minor split relaxations. A requested split
     * remains a split whenever two rows with a split group were selected.
     * Fixed lecture/lab split settings are intentionally excluded here.
     *
     * @param list<array<string, mixed>> $schedules
     * @param array<int, array<string, mixed>> $configsBySectionId
     * @return list<array<string, mixed>>
     */
    private function detectSplitSessionFallbacks(array $schedules, array $configsBySectionId, array $sections): array
    {
        $sectionsById = [];
        foreach ($sections as $section) {
            $sectionsById[(int) $section->id] = $section;
        }
        $rowsBySectionCourse = [];
        foreach ($schedules as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            $courseId = (int) ($row['course_id'] ?? 0);
            $rowsBySectionCourse[$sectionId][$courseId][] = $row;
        }

        $adjustments = [];
        foreach ($configsBySectionId as $sectionId => $config) {
            foreach (array_map('intval', $config['balanced_split_course_ids'] ?? []) as $courseId) {
                $rows = $rowsBySectionCourse[(int) $sectionId][$courseId] ?? [];
                if (count($rows) !== 1 || empty($rows[0]['split_session_fallback'])) {
                    continue;
                }

                $course = $this->loadedCourses->get($courseId);
                $section = $sectionsById[(int) $sectionId] ?? null;
                $adjustments[] = [
                    'type' => 'split_session_single_meeting_fallback',
                    'section_id' => (int) $sectionId,
                    'course_id' => $courseId,
                    'value' => 'single_meeting',
                    'section_name' => (string) ($section?->section_name ?? 'Section '.$sectionId),
                    'course_code' => (string) ($course?->course_code ?? 'Course '.$courseId),
                    'reason' => 'Some GEC courses cannot be split because the available meeting slots are full. The generator assigned this course as a single meeting instead.',
                ];
            }
        }

        return $adjustments;
    }

    /**
     * Advisory observations for a valid timetable. These are deliberately
     * separate from applied adjustments: a successful preview must never
     * mutate the selected Preferred Days automatically.
     *
     * @param array<int, array<string, mixed>> $configsBySectionId
     * @param list<Sections> $sections
     * @return list<array<string, mixed>>
     */
    private function generationRecommendations(
        array $configsBySectionId,
        array $sections,
    ): array {
        $recommendations = [];
        $sectionNames = collect($sections)->mapWithKeys(
            static fn (Sections $section): array => [(int) $section->id => (string) $section->section_name],
        )->all();
        $physicalRoomCount = count(array_filter(
            $this->generationSnapshot?->roomsById ?? [],
            static fn (array $room): bool => ! in_array((string) ($room['room_type'] ?? ''), ['online', 'field'], true),
        ));

        $teachingDays = $this->teachingDays();
        foreach ($configsBySectionId as $sectionId => $config) {
            $sectionName = $sectionNames[(int) $sectionId] ?? 'the section';
            $allowedDays = SchedulingPolicy::normalizeAllowedDays($config['allowed_days'] ?? null);
            if ($allowedDays !== null && count(array_intersect($allowedDays, $teachingDays)) < count($teachingDays) && $physicalRoomCount > 0) {
                $recommendations[] = [
                    'id' => 'preferred-days-add-day-'.$sectionId,
                    'title' => 'Recommend adding another day',
                    'detected_cause' => sprintf('%s is restricted to %s, leaving other room-time capacity unused.', $sectionName, implode(', ', $allowedDays)),
                    'suggested_adjustment' => 'Add another Preferred Day so available rooms and scheduling capacity can be used more evenly.',
                    'section_id' => (int) $sectionId,
                    'section_name' => $sectionName,
                    'course_id' => null,
                    'course_code' => null,
                    'impact' => 'low',
                    'adjustments' => [],
                    'status' => 'active',
                    'resolved' => false,
                ];
            }
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>|null  $failure
     * @return array<string, mixed>
     */
    private function attemptRecord(string $key, string $label, string $outcome, ?array $failure, string $description): array
    {
        return [
            'strategy' => $key,
            'label' => $label,
            'description' => $description,
            'outcome' => $outcome,
            'section_id' => isset($failure['section_id']) ? (int) $failure['section_id'] : null,
            'section_name' => isset($failure['section_name']) ? (string) $failure['section_name'] : null,
            'iterations' => (int) ($failure['iterations'] ?? 0),
            'search_limit_reached' => (bool) ($failure['search_limit_reached'] ?? false),
        ];
    }

    /** @param  array<int, array<string, mixed>>  $configsBySectionId */
    private function hasRelaxablePreferences(array $configsBySectionId): bool
    {
        foreach ($configsBySectionId as $config) {
            if (array_filter($config['preferred_patterns'] ?? []) !== []) {
                return true;
            }
            if (($config['selected_split_session_course_ids'] ?? []) !== []) {
                return true;
            }
            foreach (($config['delivery_modes_by_course_id'] ?? []) as $mode) {
                if ((string) $mode === 'on-site') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return Collection<int, Course>
     */
    private function loadCourses(array $configsBySectionId): Collection
    {
        $courseIds = [];
        foreach ($configsBySectionId as $config) {
            foreach (($config['course_ids'] ?? []) as $courseId) {
                $courseIds[(int) $courseId] = (int) $courseId;
            }
        }

        if ($courseIds === []) {
            return collect();
        }

        return Course::query()
            ->whereIn('id', array_values($courseIds))
            ->get()
            ->keyBy(static fn (Course $course): int => (int) $course->id);
    }

    /**
     * Recompute the derived demand fields the section ordering heuristics read.
     *
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return array<int, array<string, mixed>>
     */
    private function decorateConfigs(array $configsBySectionId, Collection $courses): array
    {
        $laboratoryRequired = [];
        foreach ($courses as $course) {
            if ((float) ($course->lab_hours ?? 0) > 0 || (string) ($course->room_type_required ?? '') === 'laboratory') {
                $laboratoryRequired[(int) $course->id] = true;
            }
        }

        foreach ($configsBySectionId as $sectionId => $config) {
            $courseIds = array_map('intval', $config['course_ids'] ?? []);
            $config['_laboratory_required_course_count'] = ($config['department_profile'] ?? null) === 'standard'
                ? 0
                : count(array_filter(
                    $courseIds,
                    static fn (int $courseId): bool => isset($laboratoryRequired[$courseId]),
                ));
            $config['_estimated_room_demand'] = $this->estimatedRoomDemand($config);
            $configsBySectionId[$sectionId] = $config;
        }

        return $configsBySectionId;
    }

    /**
     * Apply a retry strategy's adjustments to a copy of the configuration.
     *
     * Only user-selected preferences are touched. Requirements are rebuilt and
     * the section is re-validated, so a relaxation that would breach a rule is
     * discarded (null) instead of producing an invalid schedule.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  list<array<string, mixed>>  $adjustments
     * @param  Collection<int, Course>  $courses
     * @return array<int, array<string, mixed>>|null
     */
    private function applyAdjustments(
        array $sections,
        array $configsBySectionId,
        array $adjustments,
        Collection $courses,
    ): ?array {
        if ($adjustments === []) {
            // Ordering-only strategy: nothing configured changes.
            return $configsBySectionId;
        }

        $sectionsById = [];
        foreach ($sections as $section) {
            $sectionsById[(int) $section->id] = $section;
        }

        $next = $configsBySectionId;
        $touched = [];

        foreach ($adjustments as $adjustment) {
            $sectionId = (int) ($adjustment['section_id'] ?? 0);
            $courseId = (int) ($adjustment['course_id'] ?? 0);
            $type = (string) ($adjustment['type'] ?? '');
            $sectionLevelAdjustment = $type === 'disable_section_hybrid';
            if ((! $sectionLevelAdjustment && $courseId <= 0) || ! isset($next[$sectionId])) {
                continue;
            }

            $config = $this->applyAdjustment($next[$sectionId], $type, $courseId, $adjustment['value'] ?? null);
            if ($config === null) {
                continue;
            }

            $next[$sectionId] = $config;
            $touched[$sectionId] = $sectionId;
        }

        if ($touched === []) {
            return null;
        }

        foreach ($touched as $sectionId) {
            $section = $sectionsById[$sectionId] ?? null;
            if ($section === null) {
                return null;
            }

            $courseIds = array_map('intval', $next[$sectionId]['course_ids'] ?? []);
            try {
                $profile = $this->preflight()->validate($section, $courseIds, $next[$sectionId]);
            } catch (ScheduleGenerationPreflightException) {
                return null;
            }

            $next[$sectionId]['department_profile'] = $profile->value;
            $next[$sectionId]['requirements_by_course_id'] = $this->requirementBuilders()->build(
                $section,
                $courseIds,
                $next[$sectionId],
            );
        }

        return $this->decorateConfigs($next, $courses);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null null when the adjustment changes nothing
     */
    private function applyAdjustment(array $config, string $type, int $courseId, mixed $value): ?array
    {
        switch ($type) {
            case 'set_pattern':
                $pattern = SchedulingPolicy::normalizePreferredPattern($value);
                if ($pattern === null || ! array_key_exists($courseId, $config['preferred_patterns'] ?? [])) {
                    return null;
                }
                if (SchedulingPolicy::normalizePreferredPattern($config['preferred_patterns'][$courseId]) === $pattern) {
                    return null;
                }
                $config['preferred_patterns'][$courseId] = $pattern;

                return $config;

            case 'clear_pattern':
                if (! array_key_exists($courseId, $config['preferred_patterns'] ?? [])) {
                    return null;
                }
                unset($config['preferred_patterns'][$courseId]);

                return $config;

            case 'disable_lecture_lab_split':
                $splitIds = array_map('intval', $config['selected_split_session_course_ids'] ?? []);
                if (! in_array($courseId, $splitIds, true)) {
                    return null;
                }
                $config['selected_split_session_course_ids'] = array_values(array_diff($splitIds, [$courseId]));
                $remainingSplitIds = array_values(array_diff($splitIds, [$courseId]));
                $config['selected_split_session_course_ids'] = $remainingSplitIds;
                if ($remainingSplitIds === []) {
                    $config['is_hybrid'] = false;
                }

                return $config;

            case 'disable_section_hybrid':
                $splitIds = array_map('intval', $config['selected_split_session_course_ids'] ?? []);
                $isHybrid = (bool) ($config['is_hybrid'] ?? false);
                if ($splitIds === [] && ! $isHybrid) {
                    return null;
                }
                $config['selected_split_session_course_ids'] = [];
                $config['is_hybrid'] = false;

                return $config;

            case 'set_delivery_mode':
                $modes = $config['delivery_modes_by_course_id'] ?? [];
                $mode = (string) ($value ?? 'automatic');
                if ($mode === 'automatic') {
                    if (! array_key_exists($courseId, $modes)) {
                        return null;
                    }
                    unset($modes[$courseId]);
                } else {
                    if (! SchedulingPolicy::isValidDeliveryMode($mode) || ($modes[$courseId] ?? null) === $mode) {
                        return null;
                    }
                    $modes[$courseId] = $mode;
                }
                $config['delivery_modes_by_course_id'] = $modes;

                return $config;

            default:
                return null;
        }
    }

    private function feasibility(): YearLevelFeasibilityService
    {
        return $this->feasibility ??= app(YearLevelFeasibilityService::class);
    }

    private function diagnostics(): YearLevelGenerationDiagnostics
    {
        return $this->diagnostics ??= app(YearLevelGenerationDiagnostics::class);
    }

    private function planner(): YearLevelRetryStrategyPlanner
    {
        return $this->planner ??= app(YearLevelRetryStrategyPlanner::class);
    }

    private function requirementBuilders(): ScheduleRequirementBuilderResolver
    {
        return $this->requirementBuilders ??= app(ScheduleRequirementBuilderResolver::class);
    }

    private function preflight(): ScheduleGenerationPreflightService
    {
        return $this->preflight ??= app(ScheduleGenerationPreflightService::class);
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  array<string, mixed>|null  $failure
     * @return array<string, mixed>|null
     */
    private function generateForOrder(
        array $sections,
        array $configsBySectionId,
        float $deadline,
        int $seedOffset,
        ?array &$failure = null,
    ): ?array {
        $this->physicalSearchIncomplete = false;
        $this->tentativeSchedules = [];
        $evaluationConfigs = $configsBySectionId;
        $roomTypesById = Rooms::query()
            ->pluck('room_type', 'id')
            ->mapWithKeys(static fn (string $type, int|string $id): array => [(int) $id => $type])
            ->all();

        $completeCandidates = [];
        $this->collectAssignmentsForOrder(
            sections: $sections,
            configsBySectionId: $configsBySectionId,
            evaluationConfigs: $evaluationConfigs,
            roomTypesById: $roomTypesById,
            deadline: $deadline,
            seedOffset: $seedOffset,
            failure: $failure,
            completeCandidates: $completeCandidates,
            allowRoomTbaFallback: false,
        );

        if (
            $completeCandidates === []
            && ! $this->physicalSearchIncomplete
            && microtime(true) < $deadline
        ) {
            $this->aggregateFallbackUsage['room_tba_search'] = ($this->aggregateFallbackUsage['room_tba_search'] ?? 0) + 1;
            $this->tentativeSchedules = [];
            $evaluationConfigs = $configsBySectionId;
            $this->collectAssignmentsForOrder(
                sections: $sections,
                configsBySectionId: $configsBySectionId,
                evaluationConfigs: $evaluationConfigs,
                roomTypesById: $roomTypesById,
                deadline: $deadline,
                seedOffset: $seedOffset,
                failure: $failure,
                completeCandidates: $completeCandidates,
                allowRoomTbaFallback: true,
            );
        }

        if ($completeCandidates === []) {
            return null;
        }

        $evaluated = array_map(
            fn (array $candidate): array => $this->evaluator->evaluate(
                $candidate['schedules'],
                $sections,
                $candidate['configs'],
                $this->solver->departmentRoomFairness(),
                $roomTypesById,
            ),
            $completeCandidates,
        );

        usort($evaluated, fn (array $left, array $right): int => ($this->unnecessaryOnlineCount($left, $configsBySectionId) <=> $this->unnecessaryOnlineCount($right, $configsBySectionId))
            ?: ((int) $right['quality_score'] <=> (int) $left['quality_score'])
                ?: ((int) ($left['csp_score'] ?? 0) <=> (int) ($right['csp_score'] ?? 0))
        );

        return $evaluated[0];
    }

    /**
     * Count only online rows that were not explicitly configured online.
     * Physical placement must win whenever a valid alternative exists.
     */
    private function unnecessaryOnlineCount(array $candidate, array $configsBySectionId): int
    {
        $count = 0;
        foreach ($candidate['schedules'] ?? [] as $row) {
            if (($row['mode'] ?? null) !== 'online') {
                continue;
            }

            $sectionId = (int) ($row['section_id'] ?? 0);
            $courseId = (int) ($row['course_id'] ?? 0);
            $configuredMode = $configsBySectionId[$sectionId]['delivery_modes_by_course_id'][$courseId] ?? null;
            $sectionMode = $configsBySectionId[$sectionId]['mode'] ?? 'on-site';
            if ($configuredMode !== 'online' && $sectionMode !== 'online') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  array<int, array<string, mixed>>  $evaluationConfigs
     * @param  array<int, string>  $roomTypesById
     */
    private function collectAssignmentsForOrder(
        array $sections,
        array $configsBySectionId,
        array &$evaluationConfigs,
        array $roomTypesById,
        float $deadline,
        int $seedOffset,
        ?array &$failure,
        array &$completeCandidates,
        bool $allowRoomTbaFallback,
        int $index = 0,
        array $combined = [],
        array $scheduledSections = [],
        bool $alternativesRemain = false,
    ): void {
        if ($index >= count($sections)) {
            $completeCandidates[] = [
                'schedules' => $combined,
                'configs' => $evaluationConfigs,
            ];

            return;
        }

        if (
            microtime(true) >= $deadline
            || count($completeCandidates) >= self::MAX_COMPLETE_CANDIDATES_PER_ORDER
        ) {
            return;
        }

        $this->cancellation->abortIfCancelled();

        $section = $sections[$index];
        $config = $configsBySectionId[(int) $section->id];
        $remainingSections = max(1, count($sections) - $index);
        $remainingSeconds = max(1.0, $deadline - microtime(true));
        $reservedForLaterSections = max(0, $remainingSections - 1)
            * min(self::RESERVED_SECONDS_PER_REMAINING_SECTION, $remainingSeconds / $remainingSections);
        $sectionTimeBudget = max(2.0, $remainingSeconds - $reservedForLaterSections - 0.5);
        $solutions = $this->solveSectionWithRetries(
            section: $section,
            config: $config,
            timeBudget: $sectionTimeBudget,
            seedOffset: $seedOffset,
            allowRoomTbaFallback: $allowRoomTbaFallback,
            maxAttemptSeconds: $alternativesRemain ? self::SIBLING_ATTEMPT_SECONDS : null,
        );

        if ($solutions === []) {
            $failure = $this->sectionFailure($section, $config, $this->loadedCourses);

            return;
        }

        $evaluationConfigs[(int) $section->id]['forced_days_by_course_id'] =
            $this->solver->generationForcedDaysByCourseId();

        $nextScheduledSections = [...$scheduledSections, $section];
        $partialConfigs = array_intersect_key(
            $evaluationConfigs,
            array_flip(array_map(static fn (Sections $item): int => (int) $item->id, $nextScheduledSections)),
        );
        $partialCandidates = array_map(
            static fn (array $solution): array => array_merge($solution, [
                'schedules' => array_merge($combined, $solution['schedules'] ?? []),
            ]),
            $solutions,
        );
        $ranked = $this->evaluator->rank(
            $partialCandidates,
            $nextScheduledSections,
            $partialConfigs,
            $this->solver->departmentRoomFairness(),
            $roomTypesById,
            count($nextScheduledSections) === count($sections),
        );

        foreach (array_values($ranked) as $rank => $candidate) {
            $selectedSchedules = array_slice($candidate['schedules'], count($combined));
            $this->tentativeSchedules = array_merge($combined, $selectedSchedules);

            $this->collectAssignmentsForOrder(
                sections: $sections,
                configsBySectionId: $configsBySectionId,
                evaluationConfigs: $evaluationConfigs,
                roomTypesById: $roomTypesById,
                deadline: $deadline,
                seedOffset: $seedOffset,
                failure: $failure,
                completeCandidates: $completeCandidates,
                allowRoomTbaFallback: $allowRoomTbaFallback,
                index: $index + 1,
                combined: array_merge($combined, $selectedSchedules),
                scheduledSections: $nextScheduledSections,
                alternativesRemain: $rank < count($ranked) - 1,
            );

            if (
                microtime(true) >= $deadline
                || count($completeCandidates) >= self::MAX_COMPLETE_CANDIDATES_PER_ORDER
            ) {
                return;
            }
        }
    }

    /**
     * A failure record rich enough for bottleneck detection: which of the
     * section's courses carry a fixed pattern, a lecture/lab split, a laboratory
     * requirement, or a forced physical placement.
     *
     * @param  array<string, mixed>  $config
     * @param  Collection<int, Course>  $courses
     * @return array<string, mixed>
     */
    private function sectionFailure(Sections $section, array $config, Collection $courses): array
    {
        $courseIds = array_map('intval', $config['course_ids'] ?? []);
        $splitIds = array_map('intval', $config['selected_split_session_course_ids'] ?? []);
        $balancedSplitIds = array_map('intval', $config['balanced_split_course_ids'] ?? []);
        $hybridSplitIds = array_map('intval', $config['hybrid_split_course_ids'] ?? []);

        $patternCourses = [];
        foreach (($config['preferred_patterns'] ?? []) as $courseId => $pattern) {
            $normalized = SchedulingPolicy::normalizePreferredPattern($pattern);
            if ($normalized === null) {
                continue;
            }

            $patternCourses[] = [
                'course_id' => (int) $courseId,
                'course_code' => $this->courseCode($courses, (int) $courseId),
                'pattern' => $normalized,
            ];
        }

        $splitCourses = array_values(array_filter(array_map(
            fn (int $courseId): ?array => ($course = $courses->get($courseId)) !== null
                && ! SchedulingPolicy::isFieldCourse($course, (int) $section->department_id)
                ? [
                    'course_id' => $courseId,
                    'course_code' => $this->courseCode($courses, $courseId),
                ]
                : null,
            $splitIds,
        )));

        $balancedSplitCourses = array_values(array_filter(array_map(
            fn (int $courseId): ?array => ($course = $courses->get($courseId)) !== null
                && ! in_array($courseId, $hybridSplitIds, true)
                && ! SchedulingPolicy::isFieldCourse($course, (int) $section->department_id)
                ? [
                    'course_id' => $courseId,
                    'course_code' => $this->courseCode($courses, $courseId),
                ]
                : null,
            $balancedSplitIds,
        )));
        $hybridSplitSlotAvailable = false;
        foreach ($balancedSplitCourses as $balancedSplitCourse) {
            $balancedCourse = $courses->get((int) $balancedSplitCourse['course_id']);
            if ($balancedCourse !== null && $this->hasVacantHybridSplitSlot($balancedCourse, $section, $config)) {
                $hybridSplitSlotAvailable = true;
                break;
            }
        }

        $laboratoryCourses = [];
        foreach ($courseIds as $courseId) {
            $course = $courses->get($courseId);
            if ($course !== null && SchedulingPolicy::isLaboratoryCourse($course)) {
                $laboratoryCourses[] = [
                    'course_id' => $courseId,
                    'course_code' => $this->courseCode($courses, $courseId),
                ];
            }
        }

        $forcedOnSiteCourses = [];
        foreach (($config['delivery_modes_by_course_id'] ?? []) as $courseId => $mode) {
            if ((string) $mode === 'on-site') {
                $forcedOnSiteCourses[] = [
                    'course_id' => (int) $courseId,
                    'course_code' => $this->courseCode($courses, (int) $courseId),
                ];
            }
        }

        return [
            'section_id' => (int) $section->id,
            'section_name' => (string) $section->section_name,
            'course_count' => count($courseIds),
            'pattern_courses' => $patternCourses,
            'split_courses' => array_values($splitCourses),
            'balanced_split_courses' => $balancedSplitCourses,
            'hybrid_split_slot_available' => $hybridSplitSlotAvailable,
            'laboratory_courses' => $laboratoryCourses,
            'forced_on_site_courses' => $forcedOnSiteCourses,
            'iterations' => $this->solver->iterationsUsed(),
            'search_limit_reached' => $this->solver->searchLimitReached(),
        ];
    }

    /**
     * The days this department books: Sunday only once its secretary has
     * enabled Sunday classes.
     *
     * @return list<string>
     */
    private function teachingDays(): array
    {
        return SchedulingPolicy::teachingDays(
            (bool) ($this->generationSnapshot?->departmentSettings['sunday_classes_enabled'] ?? false),
        );
    }

    /**
     * Check the captured room/schedule snapshot for at least one vacant 1.5-hour
     * physical slot. This gates the Hybrid Split suggestion; it is not a solver
     * placement and therefore does not change generation outcomes.
     */
    private function hasVacantHybridSplitSlot(Course $course, Sections $section, array $config): bool
    {
        if ((int) ($course->lab_hours ?? 0) > 0 || $this->generationSnapshot === null) {
            return false;
        }

        $rooms = array_filter(
            $this->generationSnapshot->roomsById,
            static fn (array $room): bool => in_array((string) ($room['room_type'] ?? ''), ['lecture', 'laboratory'], true)
                && (string) ($room['status'] ?? 'available') === 'available',
        );
        if ($rooms === []) {
            return false;
        }

        $days = array_values(array_intersect(
            SchedulingPolicy::normalizeAllowedDays($config['allowed_days'] ?? null) ?? SchedulingPolicy::DAYS,
            $this->teachingDays(),
        ));
        $durationSlots = SchedulingPolicy::hybridSplitMeetingSlots();
        $opening = SchedulingPolicy::timeToMinutes(SchedulingPolicy::openingTime());
        $persisted = $this->generationSnapshot->persistedSchedules;

        foreach ($rooms as $roomId => $room) {
            foreach ($days as $day) {
                foreach (SchedulingPolicy::generatedStartSlotsForDuration($durationSlots) as $startSlot) {
                    $endSlot = $startSlot + $durationSlots;
                    $occupied = false;
                    foreach ($persisted as $row) {
                        if ((int) ($row['room_id'] ?? 0) !== (int) $roomId || (string) ($row['day'] ?? '') !== $day) {
                            continue;
                        }

                        $rowStart = intdiv(max(0, SchedulingPolicy::timeToMinutes((string) ($row['start_time'] ?? '00:00')) - $opening), SchedulingPolicy::SLOT_MINUTES);
                        $rowEnd = intdiv(max(0, SchedulingPolicy::timeToMinutes((string) ($row['end_time'] ?? '00:00')) - $opening), SchedulingPolicy::SLOT_MINUTES);
                        if ($startSlot < $rowEnd && $rowStart < $endSlot) {
                            $occupied = true;
                            break;
                        }
                    }

                    if (! $occupied) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param  Collection<int, Course>  $courses */
    private function courseCode(Collection $courses, int $courseId): string
    {
        $course = $courses->get($courseId);

        return (string) ($course?->course_code ?? ('Course '.$courseId));
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return array<string, mixed>|null
     */
    private function preflightPatternFeasibility(array $sections, array $configsBySectionId, Collection $courses): ?array
    {
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            if (array_filter($config['preferred_patterns'] ?? []) === []) {
                continue;
            }

            $splitCount = count($config['selected_split_session_course_ids'] ?? [])
                + count($config['balanced_split_course_ids'] ?? []);
            $this->solver->setInputSnapshot($this->generationSnapshot);
            $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                'section_id' => (int) $section->id,
                // An empty domain here means this branch is infeasible under
                // the earlier sections' tentative placements. Let the
                // year-level search backtrack instead of aborting the run.
                'throw_on_empty_domain' => false,
                'max_solutions' => 1,
                'max_iterations' => $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 120000 : 60000,
                'timeout_seconds' => $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 8 : 4,
                'seed' => (int) ($config['seed'] ?? 1),
            ]));
            $this->recordSolverMetrics();

            if ($solutions === [] && $this->solver->iterationsUsed() === 0) {
                $failure = $this->sectionFailure($section, $config, $courses);
                $failure['preflight_pattern_conflict'] = true;

                return $failure;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private function solveSectionWithRetries(
        Sections $section,
        array $config,
        float $timeBudget,
        int $seedOffset = 0,
        bool $allowRoomTbaFallback = true,
        ?float $maxAttemptSeconds = null,
    ): array {
        $baseSeed = isset($config['seed']) ? (int) $config['seed'] : random_int(1, 1000000);
        $baseSeed += $seedOffset;
        $splitCount = count($config['selected_split_session_course_ids'] ?? [])
            + count($config['balanced_split_course_ids'] ?? []);
        $isSplitHeavy = $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD;
        $attempts = $isSplitHeavy ? self::SPLIT_HEAVY_SECTION_ATTEMPTS : self::SECTION_ATTEMPTS;
        $deadline = microtime(true) + max(1.0, $timeBudget);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $remainingSeconds = $deadline - microtime(true);
            if ($remainingSeconds < 0.5) {
                break;
            }

            // The attempt keeps its old time and step limits; within them it
            // restarts with a fresh seed and twice the steps whenever a search
            // stops at its limit. The first restart uses the attempt's own
            // seed, so a section that solves quickly is placed exactly as before.
            $attemptDeadline = microtime(true) + min($isSplitHeavy ? 24 : 6, $maxAttemptSeconds ?? INF, $remainingSeconds);
            $iterationBudget = $isSplitHeavy ? 400000 : 250000;
            $restartIterations = self::RESTART_INITIAL_ITERATIONS;
            $limitReached = false;

            for ($restart = 0; ; $restart++) {
                $restartTimeout = $attemptDeadline - microtime(true);
                if ($restart > 0 && $restartTimeout < 0.3) {
                    break;
                }

                $this->solver->setInputSnapshot($this->generationSnapshot);
                $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                    'section_id' => (int) $section->id,
                    // Branch-local infeasibility must be returned to the
                    // coordinator so it can try another section ordering/slot.
                    'throw_on_empty_domain' => false,
                    // Exhaust every physical room/laboratory combination before
                    // allowing TBA. A later retry can still use TBA when the
                    // physical-only pass proves that no complete arrangement exists.
                    'allow_room_tba_fallback' => $allowRoomTbaFallback && $attempt > 0,
                    'max_solutions' => self::SECTION_SOLUTIONS_PER_ATTEMPT,
                    'max_iterations' => min($restartIterations, $iterationBudget),
                    'timeout_seconds' => max(0.3, $restartTimeout),
                    'seed' => $baseSeed + ($attempt * 7919) + ($restart * self::RESTART_SEED_STRIDE),
                    'tentative_schedules' => $this->tentativeSchedules,
                ]));
                $this->recordSolverMetrics();
                $iterationBudget -= $this->solver->iterationsUsed();
                $limitReached = $this->solver->searchLimitReached();

                if (! $allowRoomTbaFallback) {
                    $solutions = array_values(array_filter(
                        $solutions,
                        fn (array $solution): bool => ! $this->scheduleRowsContainRoomTba($solution['schedules'] ?? []),
                    ));
                }

                // A search that ended before its limit tried every candidate:
                // another seed would only repeat it.
                if ($solutions !== [] || ! $limitReached || $iterationBudget <= 0) {
                    break;
                }

                $restartIterations *= 2;
            }

            if (! $allowRoomTbaFallback && $solutions === [] && $limitReached) {
                // An iteration/timeout stop is not proof that every valid
                // laboratory placement was exhausted. Do not open Room TBA
                // for an inconclusive physical search; let the year-level
                // retry/order ladder try again instead.
                $this->physicalSearchIncomplete = true;
            }

            if ($solutions !== []) {
                return $solutions;
            }
        }

        return [];
    }

    private function resetMetrics(): void
    {
        $this->metricsStartedAt = microtime(true);
        $this->aggregateMetrics = [
            'variable_count' => 0,
            'candidate_count_before' => 0,
            'candidate_count_after' => 0,
            'iterations' => 0,
            'solver_attempts' => 0,
            'search_limit_reached' => 0,
        ];
        $this->aggregatePrunedByConstraint = [];
        $this->aggregateFallbackUsage = [];
    }

    private function recordSolverMetrics(): void
    {
        $metrics = $this->solver->generationMetrics();
        $this->aggregateMetrics['variable_count'] += $metrics->variableCount;
        $this->aggregateMetrics['candidate_count_before'] += $metrics->candidateCountBefore;
        $this->aggregateMetrics['candidate_count_after'] += $metrics->candidateCountAfter;
        $this->aggregateMetrics['iterations'] += $metrics->iterations;
        $this->aggregateMetrics['solver_attempts'] += max(1, $metrics->solverAttempts);
        $this->aggregateMetrics['search_limit_reached'] = max(
            $this->aggregateMetrics['search_limit_reached'],
            $metrics->searchLimitReached ? 1 : 0,
        );

        foreach ($metrics->prunedByConstraint as $ruleId => $count) {
            $this->aggregatePrunedByConstraint[$ruleId] = ($this->aggregatePrunedByConstraint[$ruleId] ?? 0) + $count;
        }
        foreach ($metrics->fallbackUsage as $fallback => $count) {
            $this->aggregateFallbackUsage[$fallback] = ($this->aggregateFallbackUsage[$fallback] ?? 0) + $count;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    private function reportedMetrics(array $attempts): array
    {
        $retryReasons = [];
        foreach ($attempts as $attempt) {
            $strategy = (string) ($attempt['strategy'] ?? '');
            if ($strategy === '' || $strategy === 'baseline') {
                continue;
            }

            $retryReasons[] = $strategy.':'.(string) ($attempt['outcome'] ?? 'unknown');
        }

        $metrics = new SchedulingGenerationMetrics(
            operation: 'year_level_schedule_generation',
            variableCount: $this->aggregateMetrics['variable_count'] ?? 0,
            candidateCountBefore: $this->aggregateMetrics['candidate_count_before'] ?? 0,
            candidateCountAfter: $this->aggregateMetrics['candidate_count_after'] ?? 0,
            prunedByConstraint: $this->aggregatePrunedByConstraint,
            iterations: $this->aggregateMetrics['iterations'] ?? 0,
            searchLimitReached: (bool) ($this->aggregateMetrics['search_limit_reached'] ?? false),
            solverAttempts: $this->aggregateMetrics['solver_attempts'] ?? 0,
            retryReasons: array_values(array_unique($retryReasons)),
            elapsedMs: $this->metricsStartedAt > 0.0 ? max(0.0, (microtime(true) - $this->metricsStartedAt) * 1000) : 0.0,
            fallbackUsage: $this->aggregateFallbackUsage,
            metadata: ['snapshot_contract_adopted' => false],
        );

        return ($this->metricsReporter ??= app(SchedulingMetricsReporter::class))->report($metrics);
    }

    /** @param list<array<string, mixed>> $rows */
    private function scheduleRowsContainRoomTba(array $rows): bool
    {
        foreach ($rows as $row) {
            if (
                ($row['meeting_type'] ?? null) === 'laboratory'
                && ($row['mode'] ?? 'on-site') === 'on-site'
                && empty($row['room_id'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Section orderings this run may explore. `offset` rotates the list so a
     * retry starts from an ordering the baseline attempt never reached.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return list<list<Sections>>
     */
    private function candidateOrders(array $sections, array $configsBySectionId, int $offset = 0): array
    {
        $ascending = array_values($sections);
        usort($ascending, static fn (Sections $a, Sections $b): int => (int) $a->id <=> (int) $b->id);

        $resourceHeavyFirst = $ascending;
        usort($resourceHeavyFirst, function (Sections $a, Sections $b) use ($configsBySectionId): int {
            $aDemand = $this->sectionResourceDemandScore($configsBySectionId[(int) $a->id] ?? []);
            $bDemand = $this->sectionResourceDemandScore($configsBySectionId[(int) $b->id] ?? []);

            return $bDemand <=> $aDemand ?: ((int) $a->id <=> (int) $b->id);
        });

        $orders = [$resourceHeavyFirst, $ascending, array_reverse($ascending)];
        for ($rotation = 1; $rotation < min(4, count($ascending)); $rotation++) {
            $orders[] = array_merge(
                array_slice($resourceHeavyFirst, $rotation),
                array_slice($resourceHeavyFirst, 0, $rotation),
            );
        }

        $unique = [];
        foreach ($orders as $order) {
            $key = implode(',', array_map(static fn (Sections $section): int => (int) $section->id, $order));
            $unique[$key] = $order;
        }

        $all = array_values($unique);
        if ($all === []) {
            return [];
        }

        $offset = ((int) $offset % count($all) + count($all)) % count($all);
        $rotated = array_merge(array_slice($all, $offset), array_slice($all, 0, $offset));

        return array_slice($rotated, 0, self::MAX_SECTION_ORDER_CANDIDATES);
    }

    /** @param  array<string, mixed>  $config */
    private function sectionResourceDemandScore(array $config): int
    {
        $courseCount = count(array_unique(array_map('intval', $config['course_ids'] ?? [])));
        $labRequiredCount = (int) ($config['_laboratory_required_course_count'] ?? 0);
        $splitLabCount = count(array_unique(array_map('intval', $config['selected_split_session_course_ids'] ?? [])));
        $gecSplitCount = count(array_unique(array_map('intval', $config['balanced_split_course_ids'] ?? [])));
        $estimatedRoomDemand = (int) ($config['_estimated_room_demand'] ?? $this->estimatedRoomDemand($config));

        return ($labRequiredCount * 1_000_000)
            + ($estimatedRoomDemand * 10_000)
            + ($splitLabCount * 1000)
            + ($gecSplitCount * 500)
            + $courseCount;
    }

    /** @param  array<string, mixed>  $config */
    private function estimatedRoomDemand(array $config): int
    {
        $courseCount = count(array_unique(array_map('intval', $config['course_ids'] ?? [])));
        $splitLabCount = count(array_unique(array_map('intval', $config['selected_split_session_course_ids'] ?? [])));
        $gecSplitCount = count(array_unique(array_map('intval', $config['balanced_split_course_ids'] ?? [])));
        $forcedOnSiteCount = count(array_filter(
            $config['delivery_modes_by_course_id'] ?? [],
            static fn (mixed $mode): bool => $mode === 'on-site',
        ));
        $forcedOnlineCount = count(array_filter(
            $config['delivery_modes_by_course_id'] ?? [],
            static fn (mixed $mode): bool => $mode === 'online',
        ));

        return max(0, $courseCount + $splitLabCount + $gecSplitCount + $forcedOnSiteCount - $forcedOnlineCount);
    }

}
