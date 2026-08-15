<?php

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class YearLevelScheduleGenerationService
{
    private const REPLACEABLE_STATUSES = ['draft', 'completed', 'revision'];

    private const SECTION_ATTEMPTS = 2;

    private const SPLIT_HEAVY_SECTION_ATTEMPTS = 2;

    private const SPLIT_HEAVY_COURSE_THRESHOLD = 3;

    private const SECTION_SOLUTIONS_PER_ATTEMPT = 3;

    private const MAX_SECTION_ORDER_CANDIDATES = 2;

    private const MAX_COMPLETE_CANDIDATES_PER_ORDER = 6;

    private const PREVIEW_TIME_BUDGET_SECONDS = 135.0;

    private const RESERVED_SECONDS_PER_REMAINING_SECTION = 4.0;

    public function __construct(
        private readonly CSPSolver $solver,
        private readonly ScheduleQualityEvaluator $evaluator,
    ) {}

    public function preview(array $sections, array $configsBySectionId): array
    {
        if ($sections === []) {
            throw new RuntimeException('No active sections were found for the selected year level.');
        }

        $allCourseIds = array_values(array_unique(array_merge(...array_map(
            static fn (array $config): array => array_map('intval', $config['course_ids'] ?? []),
            $configsBySectionId,
        ))));
        $labRequiredCourseIds = Course::query()
            ->whereIn('id', $allCourseIds)
            ->where(function ($query): void {
                $query
                    ->where('lab_hours', '>', 0)
                    ->orWhere('room_type_required', 'laboratory');
            })
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $labRequiredCourseIdSet = array_flip($labRequiredCourseIds);

        foreach ($configsBySectionId as &$config) {
            $courseIds = array_map('intval', $config['course_ids'] ?? []);
            $config['_laboratory_required_course_count'] = ($config['department_profile'] ?? null) === 'standard'
                ? 0
                : count(array_intersect_key(
                    array_flip($courseIds),
                    $labRequiredCourseIdSet,
                ));
            $config['_estimated_room_demand'] = $this->estimatedRoomDemand($config);
        }
        unset($config);

        $patternFailure = $this->preflightPatternFeasibility($sections, $configsBySectionId);
        if ($patternFailure !== null) {
            throw new RuntimeException($this->failureMessage([$patternFailure]));
        }

        $candidates = [];
        $failures = [];
        $deadline = microtime(true) + self::PREVIEW_TIME_BUDGET_SECONDS;
        foreach ($this->candidateOrders($sections, $configsBySectionId) as $order) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $failure = null;
            $candidate = $this->generateForOrder($order, $configsBySectionId, $deadline, $failure);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            } elseif ($failure !== null) {
                $failures[] = $failure;
            }
        }

        if ($candidates === []) {
            throw new RuntimeException($this->failureMessage($failures));
        }

        usort($candidates, static fn (array $left, array $right): int => ((int) $right['quality_score'] <=> (int) $left['quality_score'])
            ?: ((int) ($left['csp_score'] ?? 0) <=> (int) ($right['csp_score'] ?? 0))
        );

        return $candidates[0];
    }

    private function generateForOrder(
        array $sections,
        array $configsBySectionId,
        float $deadline,
        ?array &$failure = null,
    ): ?array {
        DB::beginTransaction();

        try {
            $sectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $sections);
            Schedule::query()
                ->whereIn('section_id', $sectionIds)
                ->whereIn('status', self::REPLACEABLE_STATUSES)
                ->delete();

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
                failure: $failure,
                completeCandidates: $completeCandidates,
            );

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
        } finally {
            DB::rollBack();
        }
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
        ?array &$failure,
        array &$completeCandidates,
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
        $solutions = $this->solveSectionWithRetries($section, $config, $sectionTimeBudget);

        if ($solutions === []) {
            $failure = $this->sectionFailure($section, $config);

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
            $createdScheduleIds = [];
            foreach ($selectedSchedules as $row) {
                // Staging rows make the unchanged CSP hard-conflict checks
                // aware of sections already selected in this candidate.
                $createdScheduleIds[] = (int) Schedule::create($row)->id;
            }

            $this->collectAssignmentsForOrder(
                sections: $sections,
                configsBySectionId: $configsBySectionId,
                evaluationConfigs: $evaluationConfigs,
                roomTypesById: $roomTypesById,
                deadline: $deadline,
                failure: $failure,
                completeCandidates: $completeCandidates,
                index: $index + 1,
                combined: array_merge($combined, $selectedSchedules),
                scheduledSections: $nextScheduledSections,
            );

            if ($createdScheduleIds !== []) {
                Schedule::query()->whereIn('id', $createdScheduleIds)->delete();
            }

            if (
                microtime(true) >= $deadline
                || count($completeCandidates) >= self::MAX_COMPLETE_CANDIDATES_PER_ORDER
            ) {
                return;
            }
        }
    }

    private function sectionFailure(Sections $section, array $config): array
    {
        $preferredPatterns = array_filter($config['preferred_patterns'] ?? []);

        return [
            'section_id' => (int) $section->id,
            'section_name' => (string) $section->section_name,
            'course_count' => count($config['course_ids'] ?? []),
            'split_course_count' => count($config['selected_split_session_course_ids'] ?? []),
            'pattern_course_count' => count($preferredPatterns),
            'patterns' => array_values(array_unique(array_map('strval', $preferredPatterns))),
            'forced_on_site_count' => count(array_filter(
                $config['delivery_modes_by_course_id'] ?? [],
                static fn (mixed $mode): bool => $mode === 'on-site',
            )),
            'iterations' => $this->solver->iterationsUsed(),
            'search_limit_reached' => $this->solver->searchLimitReached(),
        ];
    }

    private function preflightPatternFeasibility(array $sections, array $configsBySectionId): ?array
    {
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            if (array_filter($config['preferred_patterns'] ?? []) === []) {
                continue;
            }

            DB::beginTransaction();
            try {
                Schedule::query()
                    ->where('section_id', (int) $section->id)
                    ->whereIn('status', self::REPLACEABLE_STATUSES)
                    ->delete();

                $splitCount = count($config['selected_split_session_course_ids'] ?? [])
                    + count($config['balanced_split_course_ids'] ?? []);
                $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                    'section_id' => (int) $section->id,
                    'max_solutions' => 1,
                    'max_iterations' => $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 120000 : 60000,
                    'timeout_seconds' => $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD ? 8 : 4,
                    'seed' => (int) ($config['seed'] ?? 1),
                ]));
            } finally {
                DB::rollBack();
            }

            if ($solutions === [] && $this->solver->iterationsUsed() === 0) {
                $failure = $this->sectionFailure($section, $config);
                $failure['preflight_pattern_conflict'] = true;

                return $failure;
            }
        }

        return null;
    }

    private function failureMessage(array $failures): string
    {
        $message = 'No year-level timetable satisfies all section constraints and available room capacity.';
        $failure = $failures[0] ?? null;

        if ($failure === null) {
            return $message;
        }

        $sectionName = $failure['section_name'] ?? 'one section';
        $courseCount = (int) ($failure['course_count'] ?? 0);
        $splitCourseCount = (int) ($failure['split_course_count'] ?? 0);
        $patternCourseCount = (int) ($failure['pattern_course_count'] ?? 0);
        $patterns = $failure['patterns'] ?? [];
        $forcedOnSiteCount = (int) ($failure['forced_on_site_count'] ?? 0);
        $iterations = (int) ($failure['iterations'] ?? 0);
        $searchLimit = (bool) ($failure['search_limit_reached'] ?? false);
        $hints = [];

        if ($courseCount > 0 && $forcedOnSiteCount >= $courseCount) {
            $hints[] = 'All courses for that section are forced to F2F; switch some course modes back to Automatic or Online if room capacity is already occupied.';
        }

        if ($splitCourseCount > 0) {
            $hints[] = sprintf(
                '%d course%s selected for lecture/lab splitting; each split course needs separate lecture and laboratory placements.',
                $splitCourseCount,
                $splitCourseCount === 1 ? ' is' : 's are',
            );
        }

        if ($patternCourseCount > 0) {
            if ((bool) ($failure['preflight_pattern_conflict'] ?? false)) {
                $hints[] = sprintf(
                    '%d course%s use fixed split pattern%s; the selected MW/TTh pattern has no valid section-level candidates. Change the GEC pattern before generating.',
                    $patternCourseCount,
                    $patternCourseCount === 1 ? '' : 's',
                    $patterns !== [] ? ' ('.implode(', ', $patterns).')' : '',
                );
            } else {
                $hints[] = sprintf(
                    '%d course%s use fixed split pattern%s. The dropdown only checks basic room capacity; the full solver also considers lecture/lab splits, section conflicts, staged year-level schedules, and room rules. Try changing one GEC pattern or generating fewer fixed patterns together.',
                    $patternCourseCount,
                    $patternCourseCount === 1 ? '' : 's',
                    $patterns !== [] ? ' ('.implode(', ', $patterns).')' : '',
                );
            }
        }

        if ($iterations === 0 && $patternCourseCount > 0 && ! (bool) ($failure['preflight_pattern_conflict'] ?? false)) {
            $hints[] = 'The section is valid by itself, but the selected pattern days may already be occupied by earlier sections in the year-level run.';
        }

        return sprintf(
            '%s First failing section: %s (%d courses). Solver used %d iterations%s.%s',
            $message,
            $sectionName,
            $courseCount,
            $iterations,
            $searchLimit ? ' and reached the search limit' : '',
            $hints !== [] ? ' '.implode(' ', $hints) : '',
        );
    }

    private function solveSectionWithRetries(Sections $section, array $config, float $timeBudget): array
    {
        $baseSeed = isset($config['seed']) ? (int) $config['seed'] : random_int(1, 1000000);
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
            ]));

            if ($solutions !== []) {
                return $solutions;
            }
        }

        return [];
    }

    private function candidateOrders(array $sections, array $configsBySectionId): array
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
        for ($offset = 1; $offset < min(4, count($ascending)); $offset++) {
            $orders[] = array_merge(
                array_slice($resourceHeavyFirst, $offset),
                array_slice($resourceHeavyFirst, 0, $offset),
            );
        }

        $unique = [];
        foreach ($orders as $order) {
            $key = implode(',', array_map(static fn (Sections $section): int => (int) $section->id, $order));
            $unique[$key] = $order;
        }

        return array_slice(array_values($unique), 0, self::MAX_SECTION_ORDER_CANDIDATES);
    }

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
