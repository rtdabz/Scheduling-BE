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

    private const RESTART_INITIAL_ITERATIONS = 2000;

    private const RESTART_SEED_STRIDE = 104729;

    private const SIBLING_ATTEMPT_SECONDS = 6.0;

    private const MAX_SECTION_ORDER_CANDIDATES = 2;

    private const MAX_COMPLETE_CANDIDATES_PER_ORDER = 6;

    private const PREVIEW_TIME_BUDGET_SECONDS = 60.0;

    private const RESERVED_SECONDS_PER_REMAINING_SECTION = 4.0;

    private const BASELINE_BUDGET_SHARE = 0.45;

    private const DRAFT_RESERVE_SECONDS = 18.0;

    private const DRAFT_SECTION_SECONDS = 4.0;

    private const MIN_RETRY_SECONDS = 8.0;

    private const MAX_RETRY_STRATEGIES = 4;

    private const INTERIM_REPORT_AFTER_SECONDS = 20.0;

    /**
     * @var Collection<int, Course>
     */
    private Collection $loadedCourses;

    private array $tentativeSchedules = [];

    private float $metricsStartedAt = 0.0;

    /** @var array<string, int> */
    private array $aggregateMetrics = [];

    /** @var array<string, int> */
    private array $aggregatePrunedByConstraint = [];

    /** @var array<string, int> */
    private array $aggregateFallbackUsage = [];

    private bool $physicalSearchIncomplete = false;

    private bool $searchCutShort = false;

    private ?SchedulingSnapshot $generationSnapshot = null;

    private GenerationCancellationToken $cancellation;

    /** @var (callable(array<string, mixed>): void)|null receives the provisional failure report once */
    private $interimReporter = null;

    private float $interimReportAt = INF;

    private float $interimReportAfterSeconds = self::INTERIM_REPORT_AFTER_SECONDS;

    /**
     * @var array{sections: list<Sections>, configs: array<int, array<string, mixed>>, courses: Collection<int, Course>}|null
     */
    private ?array $interimContext = null;

    /** @var list<array<string, mixed>> */
    private array $observedFailures = [];

    /** @var array{0: Sections, 1: array<string, mixed>}|null */
    private ?array $sectionInProgress = null;

    /**
     * @var array<int, int>
     */
    private array $sectionDeadEnds = [];

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
        ?callable $onInterimFailure = null,
        float $interimReportAfterSeconds = self::INTERIM_REPORT_AFTER_SECONDS,
    ): array {
        $this->resetMetrics();
        $this->cancellation = $cancellation ?? GenerationCancellationToken::none();
        $this->interimReporter = $onInterimFailure;
        $this->interimReportAt = $onInterimFailure === null ? INF : microtime(true) + $interimReportAfterSeconds;
        $this->interimReportAfterSeconds = $interimReportAfterSeconds;
        $this->interimContext = null;
        $this->observedFailures = [];
        $this->sectionInProgress = null;

        if ($sections === []) {
            throw new RuntimeException('No active sections were found for the selected year level.');
        }

        $this->solver->beginGenerationContext();

        $courses = $this->loadedCourses = $this->loadCourses($configsBySectionId);
        $configsBySectionId = $this->decorateConfigs($configsBySectionId, $courses);
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
        $draftDeadline = $startedAt + self::PREVIEW_TIME_BUDGET_SECONDS;
        $hardDeadline = $draftDeadline - self::DRAFT_RESERVE_SECONDS;
        $retryPossible = count($sections) > 1;
        $baselineDeadline = $retryPossible
            ? $startedAt + (self::PREVIEW_TIME_BUDGET_SECONDS * self::BASELINE_BUDGET_SHARE)
            : $hardDeadline;

        $attempts = [];
        $failures = [];
        $searchIncomplete = false;
        $this->interimContext = ['sections' => $sections, 'configs' => $configsBySectionId, 'courses' => $courses];

        $patternFailure = $this->preflightPatternFeasibility($sections, $configsBySectionId, $courses);
        if ($patternFailure !== null) {
            $this->observedFailures[] = $patternFailure;
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
            $searchIncomplete = $candidate === null && $this->searchCutShort;
            $attempts[] = $this->attemptRecord(
                'baseline',
                'Original configuration',
                $candidate !== null ? 'succeeded' : 'failed',
                $baselineFailures[0] ?? null,
                'Generate with exactly the configuration you selected.',
                $searchIncomplete,
            );

            if ($candidate !== null) {
                return $this->decorateResult($candidate, null, $attempts, $configsBySectionId, $sections);
            }

            $failures = [...$failures, ...$baselineFailures];
        }

        $bottleneck = $this->diagnostics()->detectBottleneck($failures, $courses);
        if ($searchIncomplete) {
            $bottleneck = $this->diagnostics()->markSearchIncomplete($bottleneck);
        }

        $plannedStrategies = array_slice(
            $this->planner()->plan($sections, $configsBySectionId, $courses, $bottleneck),
            0,
            self::MAX_RETRY_STRATEGIES,
        );
        $strategies = array_values(array_filter(
            $plannedStrategies,
            static fn (array $strategy): bool => ($strategy['adjustments'] ?? []) === [],
        ));

        $pending = count($strategies);

        foreach ($strategies as $strategy) {
            $this->checkpoint();
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
            $cutShort = $candidate === null && $this->searchCutShort;
            if ($cutShort && ($strategy['adjustments'] ?? []) === []) {
                $searchIncomplete = true;
            }
            $attempts[] = $this->attemptRecord(
                $key,
                $label,
                $candidate !== null ? 'succeeded' : 'failed',
                $retryFailures[0] ?? null,
                $description,
                $cutShort,
            );

            if ($candidate !== null) {
                return $this->decorateResult($candidate, $strategy, $attempts, $configsBySectionId, $sections, $bottleneck);
            }

            $failures = [...$failures, ...$retryFailures];
        }

        $bottleneck = $this->diagnostics()->detectBottleneck($failures, $courses) ?? $bottleneck;
        if ($searchIncomplete) {
            $bottleneck = $this->diagnostics()->markSearchIncomplete($bottleneck);
        }
        $recommendations = $this->diagnostics()->searchRecommendations(
            $bottleneck,
            $plannedStrategies,
            $courses,
            $configsBySectionId,
            $this->suggestedPreferredDay($configsBySectionId),
            $searchIncomplete,
        );
        $message = $this->diagnostics()->searchMessage($bottleneck, $attempts, $searchIncomplete);

        $draft = $this->bestEffortDraft($sections, $configsBySectionId, $courses, $draftDeadline);
        if ($draft !== null && $draft['unplaced_courses'] === []) {
            unset($draft['unplaced_courses']);

            return $this->decorateResult($draft, null, $attempts, $configsBySectionId, $sections);
        }
        if ($draft !== null) {
            return $this->decorateDraftResult($draft, $attempts, $sections, $bottleneck, $recommendations, $message);
        }

        throw new YearLevelGenerationException(
            $message,
            YearLevelGenerationException::STAGE_SEARCH,
            bottleneck: $bottleneck,
            attempts: $attempts,
            recommendations: $recommendations,
            generationMetrics: $this->reportedMetrics($attempts),
            searchIncomplete: $searchIncomplete,
        );
    }

    /**
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
        $this->searchCutShort = false;
        foreach ($this->candidateOrders($sections, $configsBySectionId, $orderOffset) as $order) {
            $this->checkpoint();
            if (microtime(true) >= $deadline) {
                $this->searchCutShort = true;
                break;
            }

            $failure = null;
            $candidate = $this->generateForOrder($order, $configsBySectionId, $deadline, $seedOffset, $failure);
            if ($candidate !== null) {
                return $candidate;
            }

            if ($failure !== null) {
                $failures[] = $failure;
                $this->observedFailures[] = $failure;
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
     * @param  array<string, mixed>|null  $bottleneck  what blocked the original configuration, when a retry was needed
     * @return array<string, mixed>
     */
    private function decorateResult(
        array $candidate,
        ?array $strategy,
        array $attempts,
        array $configsBySectionId,
        array $sections,
        ?array $bottleneck = null,
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
            $bottleneck,
            $attempts,
        );
        $candidate['recommendations'] = $this->generationRecommendations($configsBySectionId);
        $candidate['generation_metrics'] = $this->reportedMetrics($attempts);

        return $candidate;
    }

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return array<string, mixed>|null null when nothing could be placed
     */
    private function bestEffortDraft(array $sections, array $configsBySectionId, Collection $courses, float $deadline): ?array
    {
        $order = $this->candidateOrders($sections, $configsBySectionId)[0] ?? [];
        $roomTypesById = Rooms::query()
            ->pluck('room_type', 'id')
            ->mapWithKeys(static fn (string $type, int|string $id): array => [(int) $id => $type])
            ->all();

        $this->tentativeSchedules = [];
        $combined = [];
        $unplaced = [];
        $evaluationConfigs = [];
        $placedSections = [];

        foreach ($order as $position => $section) {
            $sectionId = (int) $section->id;
            $original = $configsBySectionId[$sectionId];
            $config = $original;

            while (($courseIds = array_map('intval', $config['course_ids'] ?? [])) !== []) {
                $remaining = $deadline - microtime(true);
                if ($remaining < 1.0) {
                    foreach ($courseIds as $courseId) {
                        $unplaced[] = $this->unplacedCourse($section, $original, $courseId, $courses, timedOut: true);
                    }
                    break;
                }

                $budget = max(1.0, min(self::DRAFT_SECTION_SECONDS, $remaining / max(1, count($order) - $position)));
                $solutions = $this->solveSectionWithRetries(
                    section: $section,
                    config: $config,
                    timeBudget: $budget,
                    maxAttemptSeconds: $budget / 2,
                );

                if ($solutions !== []) {
                    $combined = [...$combined, ...($solutions[0]['schedules'] ?? [])];
                    $this->tentativeSchedules = $combined;
                    $evaluationConfigs[$sectionId] = $config;
                    $evaluationConfigs[$sectionId]['forced_days_by_course_id'] = $this->solver->generationForcedDaysByCourseId();
                    $placedSections[] = $section;
                    break;
                }

                $blocking = (int) ($this->blockingCourse($courses)['course_id'] ?? 0);
                if (! in_array($blocking, $courseIds, true)) {
                    $blocking = $courseIds[array_key_last($courseIds)];
                }
                $unplaced[] = $this->unplacedCourse($section, $original, $blocking, $courses);
                $config = $this->withoutCourse($config, $blocking);
            }
        }

        if ($combined === []) {
            return null;
        }

        $draft = $this->evaluator->evaluate(
            $combined,
            $placedSections,
            $evaluationConfigs,
            $this->solver->departmentRoomFairness(),
            $roomTypesById,
        );
        $draft['unplaced_courses'] = $unplaced;

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withoutCourse(array $config, int $courseId): array
    {
        $drop = static fn (mixed $ids): array => array_values(array_diff(array_map('intval', (array) $ids), [$courseId]));

        $config['course_ids'] = $drop($config['course_ids'] ?? []);
        $config['selected_split_session_course_ids'] = $drop($config['selected_split_session_course_ids'] ?? []);
        $config['balanced_split_course_ids'] = $drop($config['balanced_split_course_ids'] ?? []);
        $config['hybrid_split_course_ids'] = $drop($config['hybrid_split_course_ids'] ?? []);
        if ($config['selected_split_session_course_ids'] === []) {
            $config['is_hybrid'] = false;
        }
        foreach (['requirements_by_course_id', 'preferred_patterns', 'delivery_modes_by_course_id'] as $key) {
            if (isset($config[$key]) && is_array($config[$key])) {
                unset($config[$key][$courseId], $config[$key][(string) $courseId]);
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config  the section's configuration as the user set it
     * @param  Collection<int, Course>  $courses
     * @return array<string, mixed>
     */
    private function unplacedCourse(Sections $section, array $config, int $courseId, Collection $courses, bool $timedOut = false): array
    {
        $requirements = (array) ($config['requirements_by_course_id'][$courseId] ?? []);
        $meetings = array_values(array_map(
            static fn (array $requirement): array => [
                'meeting_type' => count($requirements) > 1 && in_array($requirement['component_type'] ?? null, ['lecture', 'laboratory'], true)
                    ? (string) $requirement['component_type']
                    : null,
                'duration_slots' => (int) ($requirement['duration_slots'] ?? 0),
                'modes' => array_values((array) ($requirement['allowed_delivery_modes'] ?? [])),
            ],
            array_filter($requirements, 'is_array'),
        ));

        $course = $courses->get($courseId);
        $isIn = static fn (string $key): bool => in_array($courseId, array_map('intval', (array) ($config[$key] ?? [])), true);
        $pattern = SchedulingPolicy::normalizePreferredPattern($config['preferred_patterns'][$courseId] ?? null);

        $shape = null;
        if ($isIn('hybrid_split_course_ids')) {
            $shape = 'online_split';
            $slots = SchedulingPolicy::hybridSplitMeetingSlots();
            $meetings = [
                ['meeting_type' => 'lecture', 'duration_slots' => $slots, 'modes' => ['on-site']],
                ['meeting_type' => 'lecture', 'duration_slots' => $slots, 'modes' => ['online']],
            ];
        } elseif ($isIn('balanced_split_course_ids') && count($meetings) === 1 && $meetings[0]['duration_slots'] >= 2) {
            $shape = 'split';
            $half = intdiv($meetings[0]['duration_slots'], 2);
            $meetings = [
                ['meeting_type' => 'lecture', 'duration_slots' => $half, 'modes' => $meetings[0]['modes']],
                ['meeting_type' => 'lecture', 'duration_slots' => $half, 'modes' => $meetings[0]['modes']],
            ];
        }

        $reason = match (true) {
            $timedOut => 'The generator ran out of time before it reached this course.',
            $pattern !== null => "No time fits its fixed {$pattern} pattern alongside the section's other classes.",
            $isIn('selected_split_session_course_ids') => "No free days fit its lecture and laboratory meetings alongside the section's other classes.",
            $isIn('balanced_split_course_ids') => "No two free days fit its Split Session alongside the section's other classes.",
            $course !== null && SchedulingPolicy::isLaboratoryCourse($course) => "No laboratory room is free for it alongside the section's other classes.",
            default => "No free time and room fits it alongside the section's other classes.",
        };

        return [
            'section_id' => (int) $section->id,
            'section_name' => (string) $section->section_name,
            'course_id' => $courseId,
            'course_code' => $this->courseCode($courses, $courseId),
            'reason' => $reason,
            'shape' => $shape,
            'meetings' => $meetings,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<array<string, mixed>>  $attempts
     * @param  list<Sections>  $sections
     * @param  array<string, mixed>|null  $bottleneck
     * @param  list<array<string, mixed>>  $recommendations  configuration changes that might let a full run succeed
     * @return array<string, mixed>
     */
    private function decorateDraftResult(
        array $draft,
        array $attempts,
        array $sections,
        ?array $bottleneck,
        array $recommendations,
        string $message,
    ): array {
        $count = count($draft['unplaced_courses']);
        $attempts[] = $this->attemptRecord(
            'best_effort_draft',
            'Draft with unplaced courses',
            'partial',
            null,
            "No complete timetable was found, so every course that fits was placed and {$count} left for review.",
        );

        $draft['status'] = 'partial';
        $draft['message'] = $message;
        $draft['generation_attempts'] = $attempts;
        $draft['applied_strategy'] = null;
        $draft['applied_adjustments'] = [];
        $draft['generation_changes'] = (new YearLevelGenerationChangeReport)->build(
            null,
            [],
            $draft['schedules'] ?? [],
            collect($sections)->mapWithKeys(static fn (Sections $section): array => [(int) $section->id => (string) $section->section_name])->all(),
            $this->loadedCourses->mapWithKeys(static fn ($course): array => [(int) $course->id => (string) $course->course_code])->all(),
            $bottleneck,
            $attempts,
        );
        $draft['recommendations'] = $recommendations;
        $draft['generation_metrics'] = $this->reportedMetrics($attempts);

        return $draft;
    }

    /**
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
     * @param array<int, array<string, mixed>> $configsBySectionId
     * @return list<array<string, mixed>>
     */
    private function generationRecommendations(array $configsBySectionId): array
    {
        $hasPhysicalRoom = array_filter(
            $this->generationSnapshot?->roomsById ?? [],
            static fn (array $room): bool => ! in_array((string) ($room['room_type'] ?? ''), ['online', 'field'], true),
        ) !== [];
        if (! $hasPhysicalRoom) {
            return [];
        }

        return $this->diagnostics()->preferredDayRecommendation(
            $this->suggestedPreferredDay($configsBySectionId),
            $configsBySectionId,
            timetableFits: true,
        );
    }

    private function checkpoint(): void
    {
        $this->cancellation->abortIfCancelled();

        if ($this->interimReporter === null || microtime(true) < $this->interimReportAt) {
            return;
        }

        $reporter = $this->interimReporter;
        $this->interimReporter = null;
        $this->interimReportAt = INF;

        try {
            $report = $this->interimReport();
            if ($report !== null) {
                $reporter($report);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function untilInterimReport(float $timeout): float
    {
        if ($this->interimReporter === null) {
            return $timeout;
        }

        return min($timeout, max(0.3, $this->interimReportAt - microtime(true)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function interimReport(): ?array
    {
        if ($this->interimContext === null) {
            return null;
        }

        ['sections' => $sections, 'configs' => $configs, 'courses' => $courses] = $this->interimContext;
        $failures = $this->observedFailures;
        if ($failures === [] && $this->sectionInProgress !== null) {
            [$section, $config] = $this->sectionInProgress;
            $failures[] = $this->sectionFailure($section, $config, $courses);
        }

        $bottleneck = $this->diagnostics()->detectBottleneck($failures, $courses);
        $strategies = array_slice(
            $this->planner()->plan($sections, $configs, $courses, $bottleneck),
            0,
            self::MAX_RETRY_STRATEGIES,
        );
        $elapsed = (int) round($this->interimReportAfterSeconds);

        return [
            'error_code' => 'year_level_generation_failed',
            'provisional' => true,
            'message' => $bottleneck === null
                ? sprintf('No timetable yet after %d seconds. The generator is still searching.', $elapsed)
                : sprintf(
                    'No timetable yet after %d seconds; the generator is still searching. %s looks like the blocking section: %s',
                    $elapsed,
                    $bottleneck['section_name'] !== '' ? $bottleneck['section_name'] : 'One section',
                    $bottleneck['detected_cause'],
                ),
            'stage' => 'search',
            'blocking_constraints' => [],
            'bottleneck' => $bottleneck,
            'attempts' => [],
            'recommendations' => $this->diagnostics()->searchRecommendations(
                $bottleneck,
                $strategies,
                $courses,
                $configs,
                $this->suggestedPreferredDay($configs),
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     */
    private function suggestedPreferredDay(array $configsBySectionId): ?string
    {
        $first = $configsBySectionId[array_key_first($configsBySectionId)] ?? [];
        $allowedDays = SchedulingPolicy::normalizeAllowedDays($first['allowed_days'] ?? null);
        if ($allowedDays === null) {
            return null;
        }

        $candidates = array_values(array_diff($this->teachingDays(), $allowedDays));
        if ($candidates === []) {
            return null;
        }

        $load = array_fill_keys($candidates, 0);
        foreach ($this->generationSnapshot?->persistedSchedules ?? [] as $row) {
            $day = (string) ($row['day'] ?? '');
            if (isset($load[$day])) {
                $load[$day]++;
            }
        }

        asort($load);

        return (string) array_key_first($load);
    }

    /**
     * @param  array<string, mixed>|null  $failure
     * @return array<string, mixed>
     */
    private function attemptRecord(
        string $key,
        string $label,
        string $outcome,
        ?array $failure,
        string $description,
        bool $cutShort = false,
    ): array {
        return [
            'strategy' => $key,
            'label' => $label,
            'description' => $description,
            'outcome' => $outcome,
            'section_id' => isset($failure['section_id']) ? (int) $failure['section_id'] : null,
            'section_name' => isset($failure['section_name']) ? (string) $failure['section_name'] : null,
            'iterations' => (int) ($failure['iterations'] ?? 0),
            'search_limit_reached' => $cutShort || (bool) ($failure['search_limit_reached'] ?? false),
        ];
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
            $sectionLevelAdjustment = in_array($type, ['disable_section_hybrid', 'enable_friday_saturday_split', 'add_preferred_day'], true);
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
                $remainingSplitIds = array_values(array_diff($splitIds, [$courseId]));
                $config['selected_split_session_course_ids'] = $remainingSplitIds;
                if ($remainingSplitIds === []) {
                    $config['is_hybrid'] = false;
                }

                return $config;

            case 'disable_minor_split':
                $balancedIds = array_map('intval', $config['balanced_split_course_ids'] ?? []);
                if (! in_array($courseId, $balancedIds, true)) {
                    return null;
                }
                $config['balanced_split_course_ids'] = array_values(array_diff($balancedIds, [$courseId]));
                $config['hybrid_split_course_ids'] = array_values(array_diff(
                    array_map('intval', $config['hybrid_split_course_ids'] ?? []),
                    [$courseId],
                ));
                unset($config['preferred_patterns'][$courseId]);

                return $config;

            case 'enable_friday_saturday_split':
                if ((bool) ($config['allow_friday_saturday_split'] ?? false)) {
                    return null;
                }
                $config['allow_friday_saturday_split'] = true;

                return $config;

            case 'add_preferred_day':
                $allowedDays = SchedulingPolicy::normalizeAllowedDays($config['allowed_days'] ?? null);
                $day = (string) ($value ?? '');
                if ($allowedDays === null || in_array($day, $allowedDays, true) || ! in_array($day, $this->teachingDays(), true)) {
                    return null;
                }
                $config['allowed_days'] = SchedulingPolicy::normalizeAllowedDays([...$allowedDays, $day]);

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

        if (count($completeCandidates) >= self::MAX_COMPLETE_CANDIDATES_PER_ORDER) {
            return;
        }
        if (microtime(true) >= $deadline) {
            $this->searchCutShort = true;

            return;
        }

        $section = $sections[$index];
        $config = $configsBySectionId[(int) $section->id];
        $this->sectionInProgress = [$section, $config];
        $this->checkpoint();
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

            if (count($completeCandidates) >= self::MAX_COMPLETE_CANDIDATES_PER_ORDER) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->searchCutShort = true;

                return;
            }
        }
    }

    /**
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
            'blocking_course' => $this->blockingCourse($courses),
            'iterations' => $this->solver->iterationsUsed(),
            'search_limit_reached' => $this->solver->searchLimitReached(),
        ];
    }

    /**
     * @param  Collection<int, Course>  $courses
     * @return array{course_id: int, course_code: string, dead_ends: int}|null
     */
    private function blockingCourse(Collection $courses): ?array
    {
        if ($this->sectionDeadEnds === []) {
            return null;
        }

        $deadEnds = max($this->sectionDeadEnds);
        $courseId = (int) array_search($deadEnds, $this->sectionDeadEnds, true);

        return [
            'course_id' => $courseId,
            'course_code' => $this->courseCode($courses, $courseId),
            'dead_ends' => $deadEnds,
        ];
    }

    /**
     * @return list<string>
     */
    private function teachingDays(): array
    {
        return SchedulingPolicy::teachingDays(
            (bool) ($this->generationSnapshot?->departmentSettings['sunday_classes_enabled'] ?? false),
        );
    }

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

            $this->sectionInProgress = [$section, $config];
            $this->sectionDeadEnds = [];
            $this->checkpoint();
            $splitCount = count($config['selected_split_session_course_ids'] ?? [])
                + count($config['balanced_split_course_ids'] ?? []);
            $this->solver->setInputSnapshot($this->generationSnapshot);
            $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                'section_id' => (int) $section->id,
                'throw_on_empty_domain' => false,
                'max_solutions' => 1,
                'max_iterations' => $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 120000 : 60000,
                'timeout_seconds' => $this->untilInterimReport($splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 8.0 : 4.0),
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
        $this->sectionDeadEnds = [];
        $baseSeed = isset($config['seed']) ? (int) $config['seed'] : random_int(1, 1000000);
        $baseSeed += $seedOffset;
        $splitCount = count($config['selected_split_session_course_ids'] ?? [])
            + count($config['balanced_split_course_ids'] ?? []);
        $isSplitHeavy = $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD;
        $attempts = $isSplitHeavy ? self::SPLIT_HEAVY_SECTION_ATTEMPTS : self::SECTION_ATTEMPTS;
        $deadline = microtime(true) + max(1.0, $timeBudget);
        $cutShort = false;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $remainingSeconds = $deadline - microtime(true);
            if ($remainingSeconds < 0.5) {
                $cutShort = true;
                break;
            }

            $attemptDeadline = microtime(true) + min($isSplitHeavy ? 24 : 6, $maxAttemptSeconds ?? INF, $remainingSeconds);
            $iterationBudget = $isSplitHeavy ? 400000 : 250000;
            $restartIterations = self::RESTART_INITIAL_ITERATIONS;
            $limitReached = false;

            for ($restart = 0; ; $restart++) {
                $this->checkpoint();
                $restartTimeout = $attemptDeadline - microtime(true);
                if ($restart > 0 && $restartTimeout < 0.3) {
                    break;
                }
                $clippedTimeout = $this->untilInterimReport($restartTimeout);
                $clipped = $clippedTimeout < $restartTimeout;
                $restartTimeout = $clippedTimeout;

                $this->solver->setInputSnapshot($this->generationSnapshot);
                $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                    'section_id' => (int) $section->id,
                    'throw_on_empty_domain' => false,
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

                if ($clipped && $solutions === [] && $iterationBudget > 0) {
                    continue;
                }

                if ($solutions !== [] || ! $limitReached || $iterationBudget <= 0) {
                    break;
                }

                $restartIterations *= 2;
            }

            if (! $allowRoomTbaFallback && $solutions === [] && $limitReached) {
                $this->physicalSearchIncomplete = true;
            }

            if ($solutions !== []) {
                return $solutions;
            }
            $cutShort = $limitReached;
        }

        if ($cutShort) {
            $this->searchCutShort = true;
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
        foreach ($this->solver->deadEndsByCourseId() as $courseId => $count) {
            $this->sectionDeadEnds[$courseId] = ($this->sectionDeadEnds[$courseId] ?? 0) + $count;
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
        $modes = $config['delivery_modes_by_course_id'] ?? [];
        $gecSplitCount = count(array_filter(
            array_unique(array_map('intval', $config['balanced_split_course_ids'] ?? [])),
            static fn (int $courseId): bool => ($modes[$courseId] ?? null) !== 'online',
        ));
        $forcedOnSiteCount = count(array_filter($modes, static fn (mixed $mode): bool => $mode === 'on-site'));
        $forcedOnlineCount = count(array_filter($modes, static fn (mixed $mode): bool => $mode === 'online'));

        return max(0, $courseCount + $splitLabCount + $gecSplitCount + $forcedOnSiteCount - $forcedOnlineCount);
    }

}
