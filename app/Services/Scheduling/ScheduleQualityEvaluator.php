<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Sections;

/**
 * Scores complete, already-valid CSP candidates. This service never creates
 * placements and never replaces RuleEngine/CSP hard-constraint validation.
 */
class ScheduleQualityEvaluator
{
    private const COMPONENT_BASE = 250000;

    private const FULLY_ONLINE_SECTION_WEIGHT = 100000;

    private const SUNDAY_BLOCK_WEIGHT = 50000;

    private const SATURDAY_BLOCK_WEIGHT = 10000;

    private const UNNECESSARY_ONLINE_WEIGHT = 1400;

    private const UNUSED_ROOM_WITH_ONLINE_WEIGHT = 2000;

    private const ONLINE_TARGET_DEVIATION_WEIGHT = 4000;

    private const REGULAR_TARGET_DEVIATION_WEIGHT = 5000;

    private const LAB_TARGET_DEVIATION_WEIGHT = 400;

    private const PHYSICAL_RATE_SPREAD_WEIGHT = 50000;

    private const PHYSICAL_RATE_VARIANCE_WEIGHT = 60000;

    private const FIRST_SECTION_ADVANTAGE_WEIGHT = 75000;

    private const LAB_RATE_SPREAD_WEIGHT = 900;

    private const YEAR_LEVEL_RATE_SPREAD_WEIGHT = 900;

    private const DOMINANT_PHYSICAL_SHARE_WEIGHT = 30000;

    private const ROOM_CONCENTRATION_WEIGHT = 35;

    private const WEEKDAY_TO_WEEKEND_MIGRATION_WEIGHT = 15000;

    private const WEEKDAY_PHYSICAL_TO_ONLINE_MIGRATION_WEIGHT = 25000;

    private const ROOM_IDLE_GAP_SLOT_WEIGHT = 1200;

    private const SHORT_ROOM_IDLE_GAP_WEIGHT = 12000;

    private const FILLABLE_ROOM_IDLE_GAP_WEIGHT = 5000;

    private const LAB_ROOM_MISMATCH_WEIGHT = 12000;

    private const SECTION_IDLE_GAP_OCCURRENCE_WEIGHT = 8000;

    private const SECTION_IDLE_GAP_SLOT_WEIGHT = 2500;

    private const CONFIGURATION_VIOLATION_WEIGHT = 100000;

    /**
     * @param  list<array<string, mixed>>  $schedules
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  array<string, mixed>  $fairness
     * @param  array<int, string>  $roomTypesById
     * @return array<string, mixed>
     */
    public function evaluate(
        array $schedules,
        array $sections,
        array $configsBySectionId = [],
        array $fairness = [],
        array $roomTypesById = [],
    ): array {
        $summary = $this->buildSectionSummary($schedules, $sections);

        $resourcePenalties = [
            'unnecessary_online' => $this->unnecessaryOnlinePenalty($summary, $fairness),
            'unused_rooms_with_online_classes' => $this->unusedRoomsWithOnlinePenalty($schedules, $summary, $fairness),
            'laboratory_room_mismatch' => $this->laboratoryRoomMismatchPenalty($schedules, $roomTypesById),
            'room_concentration' => $this->roomConcentrationPenalty($schedules, $fairness),
            'room_idle_gaps' => $this->roomIdleGapPenalty($schedules),
            'weekend_usage' => $this->weekendPenalty($schedules),
            'weekday_capacity_migration' => $this->weekdayCapacityMigrationPenalty($summary, $fairness),
        ];
        $fairnessPenalties = [
            'fully_online_sections' => $this->fullyOnlineSectionPenalty($summary),
            'regular_physical_targets' => $this->targetDeviationPenalty(
                $summary,
                'regular_physical',
                $fairness['section_regular_physical_targets'] ?? [],
                self::REGULAR_TARGET_DEVIATION_WEIGHT,
            ),
            'laboratory_physical_targets' => $this->targetDeviationPenalty(
                $summary,
                'laboratory_physical',
                $fairness['section_lab_physical_targets'] ?? [],
                self::LAB_TARGET_DEVIATION_WEIGHT,
            ),
            'physical_distribution' => $this->normalizedDistributionPenalty(
                $summary,
                'physical',
                'physical_demand',
                self::PHYSICAL_RATE_SPREAD_WEIGHT,
            ),
            'physical_rate_variance' => $this->normalizedRateVariancePenalty(
                $summary,
                'physical',
                'physical_demand',
                self::PHYSICAL_RATE_VARIANCE_WEIGHT,
            ),
            'first_section_physical_advantage' => $this->firstSectionPhysicalAdvantagePenalty(
                $summary,
                $sections,
            ),
            'laboratory_distribution' => $this->normalizedDistributionPenalty(
                $summary,
                'laboratory_physical',
                'laboratory_demand',
                self::LAB_RATE_SPREAD_WEIGHT,
            ),
            'year_level_physical_distribution' => $this->yearLevelDistributionPenalty($summary, $sections),
            'dominant_physical_share' => $this->dominantPhysicalSharePenalty($summary),
        ];
        $compactnessPenalties = [
            'section_idle_gaps' => $this->sectionIdleGapPenalty($schedules),
        ];
        $configurationPenalties = [
            'configuration_violations' => $this->configurationCompliancePenalty(
                $schedules,
                $configsBySectionId,
            ),
        ];

        $resourcePenalty = array_sum($resourcePenalties);
        $fairnessPenalty = array_sum($fairnessPenalties);
        $compactnessPenalty = array_sum($compactnessPenalties);
        $configurationPenalty = array_sum($configurationPenalties);
        $totalPenalty = $resourcePenalty + $fairnessPenalty + $compactnessPenalty + $configurationPenalty;

        $componentScores = [
            'resource_usage' => self::COMPONENT_BASE - $resourcePenalty,
            'fair_distribution' => self::COMPONENT_BASE - $fairnessPenalty,
            'schedule_compactness' => self::COMPONENT_BASE - $compactnessPenalty,
            'configuration_compliance' => self::COMPONENT_BASE - $configurationPenalty,
        ];
        $qualityScore = array_sum($componentScores);

        return [
            'score' => $qualityScore,
            'quality_score' => $qualityScore,
            'penalty_score' => $totalPenalty,
            'resource_usage_score' => $componentScores['resource_usage'],
            'fair_distribution_score' => $componentScores['fair_distribution'],
            'schedule_compactness_score' => $componentScores['schedule_compactness'],
            'configuration_compliance_score' => $componentScores['configuration_compliance'],
            // Retained for API compatibility; it now represents the combined
            // resource-usage and distribution quality (higher is better).
            'resource_fairness_score' => $componentScores['resource_usage'] + $componentScores['fair_distribution'],
            'quality_breakdown' => $componentScores,
            'score_breakdown' => array_merge(
                $resourcePenalties,
                $fairnessPenalties,
                $compactnessPenalties,
                $configurationPenalties,
            ),
            'schedules' => $schedules,
            'section_summaries' => $summary,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<Sections>  $sections
     * @return list<array<string, mixed>>
     */
    public function rank(
        array $candidates,
        array $sections,
        array $configsBySectionId = [],
        array $fairness = [],
        array $roomTypesById = [],
    ): array {
        $evaluated = array_map(function (array $candidate) use (
            $sections,
            $configsBySectionId,
            $fairness,
            $roomTypesById,
        ): array {
            $evaluation = $this->evaluate(
                $candidate['schedules'] ?? [],
                $sections,
                $configsBySectionId,
                $fairness,
                $roomTypesById,
            );

            $evaluation['csp_score'] = (int) ($candidate['score'] ?? 0);

            return $evaluation;
        }, $candidates);

        usort($evaluated, static fn (array $left, array $right): int => ((int) $right['quality_score'] <=> (int) $left['quality_score'])
            ?: ((int) $left['csp_score'] <=> (int) $right['csp_score'])
            ?: ((string) json_encode($left['schedules']) <=> (string) json_encode($right['schedules']))
        );

        foreach ($evaluated as $index => &$candidate) {
            $candidate['rank'] = $index + 1;
        }
        unset($candidate);

        return $evaluated;
    }

    private function buildSectionSummary(array $schedules, array $sections): array
    {
        $summary = [];
        foreach ($sections as $section) {
            $summary[(int) $section->id] = [
                'physical' => 0,
                'regular_physical' => 0,
                'laboratory_physical' => 0,
                'online' => 0,
                'weekday_physical' => 0,
                'weekend_physical' => 0,
                'physical_demand' => 0,
                'laboratory_demand' => 0,
            ];
        }

        foreach ($schedules as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            if (! isset($summary[$sectionId])) {
                continue;
            }

            $mode = (string) ($row['mode'] ?? 'on-site');
            $isLaboratory = ($row['meeting_type'] ?? null) === 'laboratory';
            $isPhysical = $mode !== 'online' && $mode !== 'field' && ($row['room_id'] ?? null) !== null;

            if ($mode === 'online') {
                $summary[$sectionId]['online']++;
            }
            if ($mode !== 'field') {
                $summary[$sectionId]['physical_demand']++;
            }
            if ($isLaboratory) {
                $summary[$sectionId]['laboratory_demand']++;
            }
            if (! $isPhysical) {
                continue;
            }

            $summary[$sectionId]['physical']++;
            if (in_array($row['day'] ?? null, ['Saturday', 'Sunday'], true)) {
                $summary[$sectionId]['weekend_physical']++;
            } else {
                $summary[$sectionId]['weekday_physical']++;
            }
            if ($isLaboratory) {
                $summary[$sectionId]['laboratory_physical']++;
            } else {
                $summary[$sectionId]['regular_physical']++;
            }
        }

        foreach ($summary as &$counts) {
            $counts['physical_rate'] = (int) $counts['physical_demand'] > 0
                ? (int) $counts['physical'] / (int) $counts['physical_demand']
                : 0.0;
            $counts['laboratory_physical_rate'] = (int) $counts['laboratory_demand'] > 0
                ? (int) $counts['laboratory_physical'] / (int) $counts['laboratory_demand']
                : 0.0;
        }
        unset($counts);

        return $summary;
    }

    private function fullyOnlineSectionPenalty(array $summary): int
    {
        return count(array_filter(
            $summary,
            static fn (array $counts): bool => $counts['online'] > 0 && $counts['physical'] === 0,
        )) * self::FULLY_ONLINE_SECTION_WEIGHT;
    }

    private function weekendPenalty(array $schedules): int
    {
        $saturday = count(array_filter($schedules, static fn (array $row): bool => ($row['day'] ?? null) === 'Saturday'));
        $sunday = count(array_filter($schedules, static fn (array $row): bool => ($row['day'] ?? null) === 'Sunday'));

        return ($sunday * self::SUNDAY_BLOCK_WEIGHT) + ($saturday * self::SATURDAY_BLOCK_WEIGHT);
    }

    private function weekdayCapacityMigrationPenalty(array $summary, array $fairness): int
    {
        $regularTargets = $fairness['section_regular_physical_targets'] ?? [];
        $labTargets = $fairness['section_lab_physical_targets'] ?? [];
        $penalty = 0;

        foreach ($summary as $sectionId => $counts) {
            $target = max(0, (int) ($regularTargets[$sectionId] ?? 0))
                + max(0, (int) ($labTargets[$sectionId] ?? 0));
            $deficit = max(0, $target - (int) $counts['weekday_physical']);
            $weekendMigration = min($deficit, (int) $counts['weekend_physical']);
            $onlineMigration = min(max(0, $deficit - $weekendMigration), (int) $counts['online']);

            $penalty += $weekendMigration * self::WEEKDAY_TO_WEEKEND_MIGRATION_WEIGHT;
            $penalty += $onlineMigration * self::WEEKDAY_PHYSICAL_TO_ONLINE_MIGRATION_WEIGHT;
        }

        return $penalty;
    }

    private function unnecessaryOnlinePenalty(array $summary, array $fairness): int
    {
        $onlineTargets = $fairness['section_online_targets'] ?? [];
        $penalty = 0;

        foreach ($summary as $sectionId => $counts) {
            $online = (int) $counts['online'];
            if (array_key_exists($sectionId, $onlineTargets)) {
                $target = max(0, (int) $onlineTargets[$sectionId]);
                $excess = max(0, $online - $target);
                $penalty += $excess * (self::UNNECESSARY_ONLINE_WEIGHT + self::ONLINE_TARGET_DEVIATION_WEIGHT);
            } else {
                $penalty += $online * self::UNNECESSARY_ONLINE_WEIGHT;
            }
        }

        return $penalty;
    }

    private function unusedRoomsWithOnlinePenalty(array $schedules, array $summary, array $fairness): int
    {
        $usedRooms = [];
        foreach ($schedules as $row) {
            if (($row['room_id'] ?? null) !== null && ! in_array($row['mode'] ?? null, ['online', 'field'], true)) {
                $usedRooms[(int) $row['room_id']] = true;
            }
        }

        $unusedRooms = max(0, (int) ($fairness['physical_rooms'] ?? 0) - count($usedRooms));
        $excessOnline = 0;
        foreach ($summary as $sectionId => $counts) {
            $target = max(0, (int) (($fairness['section_online_targets'] ?? [])[$sectionId] ?? 0));
            $excessOnline += max(0, (int) $counts['online'] - $target);
        }

        return min($unusedRooms, $excessOnline) * self::UNUSED_ROOM_WITH_ONLINE_WEIGHT;
    }

    private function laboratoryRoomMismatchPenalty(array $schedules, array $roomTypesById): int
    {
        $mismatches = 0;
        foreach ($schedules as $row) {
            if (($row['meeting_type'] ?? null) !== 'laboratory' || in_array($row['mode'] ?? null, ['online', 'field'], true)) {
                continue;
            }

            $roomId = isset($row['room_id']) ? (int) $row['room_id'] : 0;
            if ($roomId > 0 && isset($roomTypesById[$roomId]) && $roomTypesById[$roomId] !== 'laboratory') {
                $mismatches++;
            }
        }

        return $mismatches * self::LAB_ROOM_MISMATCH_WEIGHT;
    }

    private function targetDeviationPenalty(array $summary, string $countKey, array $targets, int $weight): int
    {
        $penalty = 0;
        foreach ($summary as $sectionId => $counts) {
            if (array_key_exists($sectionId, $targets)) {
                $penalty += abs((int) $counts[$countKey] - max(0, (int) $targets[$sectionId])) * $weight;
            }
        }

        return $penalty;
    }

    private function normalizedDistributionPenalty(array $summary, string $countKey, string $demandKey, int $weight): int
    {
        $rates = [];
        foreach ($summary as $counts) {
            if ((int) $counts[$demandKey] > 0) {
                $rates[] = (int) $counts[$countKey] / (int) $counts[$demandKey];
            }
        }

        return count($rates) < 2 ? 0 : (int) round((max($rates) - min($rates)) * $weight);
    }

    private function normalizedRateVariancePenalty(array $summary, string $countKey, string $demandKey, int $weight): int
    {
        $rates = [];
        foreach ($summary as $counts) {
            $demand = (int) $counts[$demandKey];
            if ($demand > 0) {
                $rates[] = (int) $counts[$countKey] / $demand;
            }
        }

        if (count($rates) < 2) {
            return 0;
        }

        $average = array_sum($rates) / count($rates);
        $variance = array_sum(array_map(
            static fn (float $rate): float => ($rate - $average) ** 2,
            $rates,
        )) / count($rates);

        return (int) round($variance * $weight);
    }

    private function firstSectionPhysicalAdvantagePenalty(array $summary, array $sections): int
    {
        if (count($sections) < 2) {
            return 0;
        }

        $firstSectionId = (int) $sections[0]->id;
        $firstRate = (float) ($summary[$firstSectionId]['physical_rate'] ?? 0.0);
        $laterRates = [];

        foreach (array_slice($sections, 1) as $section) {
            $sectionId = (int) $section->id;
            if ((int) ($summary[$sectionId]['physical_demand'] ?? 0) > 0) {
                $laterRates[] = (float) ($summary[$sectionId]['physical_rate'] ?? 0.0);
            }
        }

        if ($laterRates === []) {
            return 0;
        }

        $advantage = max(0.0, $firstRate - (array_sum($laterRates) / count($laterRates)) - 0.05);

        return (int) round($advantage * self::FIRST_SECTION_ADVANTAGE_WEIGHT);
    }

    private function yearLevelDistributionPenalty(array $summary, array $sections): int
    {
        $byYear = [];
        foreach ($sections as $section) {
            $sectionId = (int) $section->id;
            $yearLevel = (string) ($section->year_level ?? 'unknown');
            $byYear[$yearLevel]['physical'] = ($byYear[$yearLevel]['physical'] ?? 0) + (int) ($summary[$sectionId]['physical'] ?? 0);
            $byYear[$yearLevel]['demand'] = ($byYear[$yearLevel]['demand'] ?? 0) + (int) ($summary[$sectionId]['physical_demand'] ?? 0);
        }

        $rates = [];
        foreach ($byYear as $counts) {
            if ($counts['demand'] > 0) {
                $rates[] = $counts['physical'] / $counts['demand'];
            }
        }

        return count($rates) < 2 ? 0 : (int) round((max($rates) - min($rates)) * self::YEAR_LEVEL_RATE_SPREAD_WEIGHT);
    }

    private function dominantPhysicalSharePenalty(array $summary): int
    {
        $counts = array_map(static fn (array $row): int => (int) $row['physical'], $summary);
        $total = array_sum($counts);
        if ($total === 0 || count($counts) < 2) {
            return 0;
        }

        $allowedShare = (1 / count($counts)) + 0.10;

        return (int) round(max(0.0, (max($counts) / $total) - $allowedShare) * self::DOMINANT_PHYSICAL_SHARE_WEIGHT);
    }

    private function roomConcentrationPenalty(array $schedules, array $fairness): int
    {
        $usesByRoom = [];
        foreach ($schedules as $row) {
            if (($row['room_id'] ?? null) !== null && ! in_array($row['mode'] ?? null, ['online', 'field'], true)) {
                $roomId = (int) $row['room_id'];
                $usesByRoom[$roomId] = ($usesByRoom[$roomId] ?? 0) + 1;
            }
        }

        $roomCount = max((int) ($fairness['physical_rooms'] ?? 0), count($usesByRoom));
        $totalUses = array_sum($usesByRoom);
        if ($roomCount < 2 || $totalUses === 0) {
            return 0;
        }

        $average = $totalUses / $roomCount;
        $deviation = array_sum(array_map(static fn (int $uses): float => ($uses - $average) ** 2, $usesByRoom));
        $deviation += max(0, $roomCount - count($usesByRoom)) * ($average ** 2);

        return (int) round($deviation * self::ROOM_CONCENTRATION_WEIGHT);
    }

    private function roomIdleGapPenalty(array $schedules): int
    {
        $blocks = [];
        foreach ($schedules as $row) {
            if (($row['room_id'] ?? null) === null || in_array($row['mode'] ?? null, ['online', 'field'], true)) {
                continue;
            }
            $blocks[(int) $row['room_id'].':'.($row['day'] ?? '')][] = $this->minuteBlock($row);
        }

        return $this->idleGapPenalty(
            $blocks,
            self::ROOM_IDLE_GAP_SLOT_WEIGHT,
            self::SHORT_ROOM_IDLE_GAP_WEIGHT,
            self::FILLABLE_ROOM_IDLE_GAP_WEIGHT,
        );
    }

    private function sectionIdleGapPenalty(array $schedules): int
    {
        $blocks = [];
        foreach ($schedules as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            $day = (string) ($row['day'] ?? '');
            if ($sectionId > 0 && $day !== '') {
                $blocks[$sectionId.':'.$day][] = $this->minuteBlock($row);
            }
        }

        return $this->idleGapPenalty(
            $blocks,
            self::SECTION_IDLE_GAP_SLOT_WEIGHT,
            self::SECTION_IDLE_GAP_OCCURRENCE_WEIGHT,
            0,
        );
    }

    private function idleGapPenalty(array $blocksByOwnerDay, int $slotWeight, int $shortWeight, int $fillableWeight): int
    {
        $penalty = 0;
        foreach ($blocksByOwnerDay as $blocks) {
            if (count($blocks) < 2) {
                continue;
            }
            usort($blocks, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
            $occupiedUntil = (int) $blocks[0]['end'];
            foreach (array_slice($blocks, 1) as $block) {
                $gapMinutes = max(0, (int) $block['start'] - $occupiedUntil);
                if ($gapMinutes > 0) {
                    $gapSlots = (int) ceil($gapMinutes / SchedulingPolicy::SLOT_MINUTES);
                    $penalty += $gapSlots * $slotWeight;
                    if ($gapSlots <= 2) {
                        $penalty += $shortWeight;
                    }
                    if ($gapSlots >= 2) {
                        $penalty += $fillableWeight;
                    }
                }
                $occupiedUntil = max($occupiedUntil, (int) $block['end']);
            }
        }

        return $penalty;
    }

    private function configurationCompliancePenalty(array $schedules, array $configsBySectionId): int
    {
        if ($configsBySectionId === []) {
            return 0;
        }

        $rowsBySectionCourse = [];
        foreach ($schedules as $row) {
            $rowsBySectionCourse[(int) ($row['section_id'] ?? 0)][(int) ($row['course_id'] ?? 0)][] = $row;
        }

        $violations = 0;
        foreach ($configsBySectionId as $sectionId => $config) {
            $sectionRows = $rowsBySectionCourse[(int) $sectionId] ?? [];

            foreach (array_map('intval', $config['selected_split_session_course_ids'] ?? []) as $courseId) {
                $types = array_values(array_unique(array_column($sectionRows[$courseId] ?? [], 'meeting_type')));
                if (! in_array('lecture', $types, true) || ! in_array('laboratory', $types, true)) {
                    $violations++;
                }
            }

            foreach (array_map('intval', $config['balanced_split_course_ids'] ?? []) as $courseId) {
                $courseRows = $sectionRows[$courseId] ?? [];
                if (count($courseRows) < 2) {
                    $violations++;

                    continue;
                }

                $pattern = $config['preferred_patterns'][$courseId]
                    ?? $config['preferred_patterns'][(string) $courseId]
                    ?? null;
                if ($pattern !== null && ! $this->rowsMatchPattern($courseRows, (string) $pattern)) {
                    $violations++;
                }
            }

            foreach (($config['delivery_modes_by_course_id'] ?? []) as $courseId => $mode) {
                foreach ($sectionRows[(int) $courseId] ?? [] as $row) {
                    if (($row['mode'] ?? 'on-site') !== $mode) {
                        $violations++;
                        break;
                    }
                }
            }

            foreach (($config['anchored_schedules'] ?? []) as $anchor) {
                $matches = array_filter(
                    $sectionRows[(int) ($anchor['course_id'] ?? 0)] ?? [],
                    static fn (array $row): bool => ($row['day'] ?? null) === ($anchor['day'] ?? null)
                        && substr((string) ($row['start_time'] ?? ''), 0, 5) === substr((string) ($anchor['start_time'] ?? ''), 0, 5)
                        && substr((string) ($row['end_time'] ?? ''), 0, 5) === substr((string) ($anchor['end_time'] ?? ''), 0, 5),
                );
                if ($matches === []) {
                    $violations++;
                }
            }
        }

        return $violations * self::CONFIGURATION_VIOLATION_WEIGHT;
    }

    private function rowsMatchPattern(array $rows, string $pattern): bool
    {
        $days = array_values(array_unique(array_column($rows, 'day')));
        sort($days);
        $expected = match (strtoupper(str_replace(['-', '/'], '', $pattern))) {
            'MW' => ['Monday', 'Wednesday'],
            'TTH' => ['Thursday', 'Tuesday'],
            default => [],
        };
        sort($expected);

        return $expected === [] || $days === $expected;
    }

    private function minuteBlock(array $row): array
    {
        return [
            'start' => $this->timeToMinutes((string) ($row['start_time'] ?? '00:00')),
            'end' => $this->timeToMinutes((string) ($row['end_time'] ?? '00:00')),
        ];
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)) + [0, 0]);

        return ($hours * 60) + $minutes;
    }
}
