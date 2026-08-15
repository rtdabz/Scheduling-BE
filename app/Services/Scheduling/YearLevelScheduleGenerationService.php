<?php

namespace App\Services\Scheduling;

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

    private const SECTION_SOLUTIONS_PER_ATTEMPT = 5;

    public function __construct(
        private readonly CSPSolver $solver,
        private readonly ScheduleQualityEvaluator $evaluator,
    ) {}

    public function preview(array $sections, array $configsBySectionId): array
    {
        if ($sections === []) {
            throw new RuntimeException('No active sections were found for the selected year level.');
        }

        $candidates = [];
        $failures = [];
        foreach ($this->candidateOrders(
            $sections,
            $configsBySectionId,
            $this->hasSplitLaboratoryDemand($configsBySectionId),
        ) as $order) {
            $failure = null;
            $candidate = $this->generateForOrder($order, $configsBySectionId, $failure);
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

    private function hasSplitLaboratoryDemand(array $configsBySectionId): bool
    {
        foreach ($configsBySectionId as $config) {
            if (! empty($config['selected_split_session_course_ids'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    private function generateForOrder(array $sections, array $configsBySectionId, ?array &$failure = null): ?array
    {
        DB::beginTransaction();

        try {
            $sectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $sections);
            Schedule::query()
                ->whereIn('section_id', $sectionIds)
                ->whereIn('status', self::REPLACEABLE_STATUSES)
                ->delete();

            $combined = [];
            $scheduledSections = [];
            $roomTypesById = Rooms::query()
                ->pluck('room_type', 'id')
                ->mapWithKeys(static fn (string $type, int|string $id): array => [(int) $id => $type])
                ->all();

            foreach ($sections as $section) {
                $config = $configsBySectionId[(int) $section->id];
                $solutions = $this->solveSectionWithRetries($section, $config);

                if ($solutions === []) {
                    $failure = [
                        'section_id' => (int) $section->id,
                        'section_name' => (string) $section->section_name,
                        'course_count' => count($config['course_ids'] ?? []),
                        'split_course_count' => count($config['selected_split_session_course_ids'] ?? []),
                        'forced_on_site_count' => count(array_filter(
                            $config['delivery_modes_by_course_id'] ?? [],
                            static fn (mixed $mode): bool => $mode === 'on-site',
                        )),
                        'iterations' => $this->solver->iterationsUsed(),
                        'search_limit_reached' => $this->solver->searchLimitReached(),
                    ];

                    return null;
                }

                $scheduledSections[] = $section;
                $partialConfigs = array_intersect_key(
                    $configsBySectionId,
                    array_flip(array_map(static fn (Sections $item): int => (int) $item->id, $scheduledSections)),
                );
                $partialCandidates = array_map(
                    static fn (array $solution): array => array_merge($solution, [
                        'schedules' => array_merge($combined, $solution['schedules'] ?? []),
                    ]),
                    $solutions,
                );
                $ranked = $this->evaluator->rank(
                    $partialCandidates,
                    $scheduledSections,
                    $partialConfigs,
                    $this->solver->departmentRoomFairness(),
                    $roomTypesById,
                );
                $selectedSchedules = array_slice($ranked[0]['schedules'], count($combined));

                foreach ($selectedSchedules as $row) {
                    $combined[] = $row;
                    // Staging rows make the unchanged CSP hard-conflict checks
                    // aware of sections already selected in this candidate.
                    Schedule::create($row);
                }
            }

            return $this->evaluator->evaluate(
                $combined,
                $sections,
                $configsBySectionId,
                $this->solver->departmentRoomFairness(),
                $roomTypesById,
            );
        } finally {
            DB::rollBack();
        }
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

    private function solveSectionWithRetries(Sections $section, array $config): array
    {
        $baseSeed = isset($config['seed']) ? (int) $config['seed'] : random_int(1, 1000000);
        $splitCount = count($config['selected_split_session_course_ids'] ?? [])
            + count($config['balanced_split_course_ids'] ?? []);
        $isSplitHeavy = $splitCount >= self::SPLIT_HEAVY_COURSE_THRESHOLD;
        $attempts = $isSplitHeavy ? self::SPLIT_HEAVY_SECTION_ATTEMPTS : self::SECTION_ATTEMPTS;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $solutions = $this->solver->solveRankedFromSchema(array_merge($config, [
                'section_id' => (int) $section->id,
                'max_solutions' => self::SECTION_SOLUTIONS_PER_ATTEMPT,
                'max_iterations' => $isSplitHeavy ? 400000 : 250000,
                'timeout_seconds' => $isSplitHeavy ? 24 : 6,
                'seed' => $baseSeed + ($attempt * 7919),
            ]));

            if ($solutions !== []) {
                return $solutions;
            }
        }

        return [];
    }

    private function candidateOrders(array $sections, array $configsBySectionId, bool $hasSplitLaboratoryDemand = false): array
    {
        $ascending = array_values($sections);
        usort($ascending, static fn (Sections $a, Sections $b): int => (int) $a->id <=> (int) $b->id);

        if ($hasSplitLaboratoryDemand) {
            return [$ascending];
        }

        $demandFirst = $ascending;
        usort($demandFirst, static function (Sections $a, Sections $b) use ($configsBySectionId): int {
            $aDemand = count($configsBySectionId[(int) $a->id]['course_ids'] ?? []);
            $bDemand = count($configsBySectionId[(int) $b->id]['course_ids'] ?? []);

            return $bDemand <=> $aDemand ?: ((int) $a->id <=> (int) $b->id);
        });

        $orders = [$ascending, array_reverse($ascending), $demandFirst];
        for ($offset = 1; $offset < min(4, count($ascending)); $offset++) {
            $orders[] = array_merge(array_slice($ascending, $offset), array_slice($ascending, 0, $offset));
        }

        $unique = [];
        foreach ($orders as $order) {
            $key = implode(',', array_map(static fn (Sections $section): int => (int) $section->id, $order));
            $unique[$key] = $order;
        }

        return array_values($unique);
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
