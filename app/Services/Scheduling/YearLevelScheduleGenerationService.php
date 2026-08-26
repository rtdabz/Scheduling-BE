<?php

namespace App\Services\Scheduling;

use App\Exceptions\ScheduleGenerationPreflightException;
use App\Exceptions\YearLevelGenerationException;
use App\Models\Course;
use App\Models\Rooms;
use App\Models\Sections;
use Illuminate\Support\Collection;
use RuntimeException;

class YearLevelScheduleGenerationService
{
    private const SECTION_ATTEMPTS = 2;

    private const SPLIT_HEAVY_SECTION_ATTEMPTS = 2;

    private const SPLIT_HEAVY_COURSE_THRESHOLD = 3;

    private const SECTION_SOLUTIONS_PER_ATTEMPT = 3;

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

    public function __construct(
        private readonly CSPSolver $solver,
        private readonly ScheduleQualityEvaluator $evaluator,
        private ?YearLevelFeasibilityService $feasibility = null,
        private ?YearLevelGenerationDiagnostics $diagnostics = null,
        private ?YearLevelRetryStrategyPlanner $planner = null,
        private ?ScheduleRequirementBuilderResolver $requirementBuilders = null,
        private ?ScheduleGenerationPreflightService $preflight = null,
    ) {
        $this->loadedCourses = collect();
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return array<string, mixed>
     *
     * @throws YearLevelGenerationException when no valid timetable can be produced
     */
    public function preview(array $sections, array $configsBySectionId): array
    {
        if ($sections === []) {
            throw new RuntimeException('No active sections were found for the selected year level.');
        }

        $this->solver->beginGenerationContext();

        $courses = $this->loadedCourses = $this->loadCourses($configsBySectionId);
        $configsBySectionId = $this->decorateConfigs($configsBySectionId, $courses);

        // Feasibility pre-check: refuse only provable shortfalls, before spending
        // two minutes searching for something that cannot exist.
        $blocking = $this->feasibility()->check($sections, $configsBySectionId);
        if ($blocking !== []) {
            throw new YearLevelGenerationException(
                $this->diagnostics()->feasibilityMessage($blocking),
                YearLevelGenerationException::STAGE_FEASIBILITY,
                blockingConstraints: $blocking,
                recommendations: $this->diagnostics()->feasibilityRecommendations($blocking),
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
                return $this->decorateResult($candidate, null, $attempts);
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
                return $this->decorateResult($candidate, $strategy, $attempts);
            }

            $failures = [...$failures, ...$retryFailures];
        }

        $bottleneck = $this->diagnostics()->detectBottleneck($failures, $courses) ?? $bottleneck;

        throw new YearLevelGenerationException(
            $this->diagnostics()->searchMessage($bottleneck, $attempts),
            YearLevelGenerationException::STAGE_SEARCH,
            bottleneck: $bottleneck,
            attempts: $attempts,
            recommendations: $this->diagnostics()->searchRecommendations($bottleneck, $strategies),
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
        $candidates = [];

        foreach ($this->candidateOrders($sections, $configsBySectionId, $orderOffset) as $order) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $failure = null;
            $candidate = $this->generateForOrder($order, $configsBySectionId, $deadline, $seedOffset, $failure);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            } elseif ($failure !== null) {
                $failures[] = $failure;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $left, array $right): int => ((int) $right['quality_score'] <=> (int) $left['quality_score'])
            ?: ((int) ($left['csp_score'] ?? 0) <=> (int) ($right['csp_score'] ?? 0))
        );

        return $candidates[0];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $strategy
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    private function decorateResult(array $candidate, ?array $strategy, array $attempts): array
    {
        $candidate['generation_attempts'] = $attempts;
        $candidate['applied_strategy'] = $strategy === null ? null : [
            'key' => (string) ($strategy['key'] ?? ''),
            'label' => (string) ($strategy['label'] ?? ''),
            'description' => (string) ($strategy['description'] ?? ''),
            'impact' => (string) ($strategy['impact'] ?? 'medium'),
        ];
        $candidate['applied_adjustments'] = $strategy === null
            ? []
            : array_values((array) ($strategy['adjustments'] ?? []));

        return $candidate;
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
            ->with('categories')
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
            if ($courseId <= 0 || ! isset($next[$sectionId])) {
                continue;
            }

            $config = $this->applyAdjustment($next[$sectionId], (string) ($adjustment['type'] ?? ''), $courseId, $adjustment['value'] ?? null);
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

        if ($completeCandidates === [] && microtime(true) < $deadline) {
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

        usort($evaluated, static fn (array $left, array $right): int => ((int) $right['quality_score'] <=> (int) $left['quality_score'])
                ?: ((int) ($left['csp_score'] ?? 0) <=> (int) ($right['csp_score'] ?? 0))
        );

        return $evaluated[0];
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

        foreach ($ranked as $candidate) {
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

        $splitCourses = array_map(
            fn (int $courseId): array => [
                'course_id' => $courseId,
                'course_code' => $this->courseCode($courses, $courseId),
            ],
            $splitIds,
        );

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
            'laboratory_courses' => $laboratoryCourses,
            'forced_on_site_courses' => $forcedOnSiteCourses,
            'iterations' => $this->solver->iterationsUsed(),
            'search_limit_reached' => $this->solver->searchLimitReached(),
        ];
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
            $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                'section_id' => (int) $section->id,
                'max_solutions' => 1,
                'max_iterations' => $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 120000 : 60000,
                'timeout_seconds' => $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 8 : 4,
                'seed' => (int) ($config['seed'] ?? 1),
            ]));

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
    ): array
    {
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

            $attemptTimeout = min($isSplitHeavy ? 24 : 6, $remainingSeconds);

            $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                'section_id' => (int) $section->id,
                'max_solutions' => self::SECTION_SOLUTIONS_PER_ATTEMPT,
                'max_iterations' => $isSplitHeavy ? 400000 : 250000,
                'timeout_seconds' => $attemptTimeout,
                'seed' => $baseSeed + ($attempt * 7919),
                'tentative_schedules' => $this->tentativeSchedules,
            ]));

            if (! $allowRoomTbaFallback) {
                $solutions = array_values(array_filter(
                    $solutions,
                    fn (array $solution): bool => ! $this->scheduleRowsContainRoomTba($solution['schedules'] ?? []),
                ));
            }

            if ($solutions !== []) {
                return $solutions;
            }
        }

        return [];
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

    /** Kept as a thin compatibility seam for focused legacy score tests. */
    private function scoreCandidate(array $schedules, array $sections): array
    {
        return $this->evaluator->evaluate(
            $schedules,
            $sections,
            fairness: $this->solver->departmentRoomFairness(),
        );
    }
}
