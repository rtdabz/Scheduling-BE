<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * Picks the solutions a user is shown: a stable signature per assignment set
 * (to drop duplicates) and a greedy diversity-first selection, so the ranked
 * list offers genuinely different timetables rather than 30-minute shifts of
 * the best one. Pure: it reads only the assignments it is given.
 *
 * Extracted from CspSolver unchanged apart from names.
 */
final class SolutionDiversity
{
    public function signature(array $assignments): string
    {
        $signatureRows = [];

        foreach ($assignments as $assignment) {
            foreach ($assignment['blocks'] as $block) {
                $blockRoomId = array_key_exists('room_id', $block)
                    ? $block['room_id']
                    : ($assignment['room_id'] ?? null);

                $signatureRows[] = [
                    'course_id' => $assignment['course_id'],
                    'room_id' => $blockRoomId,
                    'preferred_pattern' => $assignment['preferred_pattern'],
                    'mode' => $block['mode'] ?? $assignment['mode'],
                    'is_hybrid' => $assignment['is_hybrid'],
                    'day' => $block['day'],
                    'start_slot' => $block['start_slot'],
                    'end_slot' => $block['end_slot'],
                ];
            }
        }

        usort(
            $signatureRows,
            static function (array $left, array $right): int {
                return [
                    $left['course_id'],
                    SchedulingPolicy::dayIndex($left['day']),
                    $left['start_slot'],
                    $left['end_slot'],
                    $left['room_id'] ?? 0,
                    $left['preferred_pattern'] ?? '',
                    $left['mode'],
                    $left['is_hybrid'] ? 1 : 0,
                ] <=> [
                    $right['course_id'],
                    SchedulingPolicy::dayIndex($right['day']),
                    $right['start_slot'],
                    $right['end_slot'],
                    $right['room_id'] ?? 0,
                    $right['preferred_pattern'] ?? '',
                    $right['mode'],
                    $right['is_hybrid'] ? 1 : 0,
                ];
            },
        );

        return hash(
            'sha256',
            (string) json_encode($signatureRows, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Selects up to $limit solutions from the scored candidate pool using a
     * greedy diversity-first algorithm.
     *
     * Algorithm:
     *   1. Seed the selection with the best-scoring (lowest penalty) solution.
     *   2. For each subsequent slot, score every remaining candidate by how
     *      different it is from all already-selected solutions, then pick the
     *      one with the highest combined diversity+quality value.
     *
     * Diversity dimensions (each contributes to the diversity score):
     *   - Day-set difference: distinct weekdays used vs. already-selected sets.
     *   - Time-band difference: morning/midday/afternoon/evening bands.
     *   - Room difference: whether a different room is used.
     *   - Meeting-pattern difference: single vs. split, or different pattern days.
     *
     * @param  array<int, array{rank: int, score: int, schedules: array, _raw: array}>  $scored
     * @return array<int, array{rank: int, score: int, schedules: array, _raw: array}>
     */
    public function selectDiverse(array $scored, int $limit): array
    {
        if ($scored === [] || $limit <= 0) {
            return [];
        }

        // Sort by ascending score (lower penalty = better quality) to bias
        // the first pick toward the best solution.
        usort(
            $scored,
            static function (array $left, array $right): int {
                if ($left['score'] !== $right['score']) {
                    return $left['score'] <=> $right['score'];
                }

                return (string) json_encode($left['schedules'])
                    <=> (string) json_encode($right['schedules']);
            },
        );

        $selected = [];
        $remaining = $scored;

        // Seed with the highest-quality solution.
        $selected[] = array_shift($remaining);

        while (count($selected) < $limit && $remaining !== []) {
            $bestIndex = 0;
            $bestCombined = PHP_INT_MIN;

            foreach ($remaining as $idx => $candidate) {
                // Diversity: how different is this candidate from every already-
                // selected solution? Sum the minimum pairwise differences.
                $minDiversity = PHP_INT_MAX;

                foreach ($selected as $sel) {
                    $diversity = $this->diversity(
                        $candidate['_raw'],
                        $sel['_raw'],
                    );

                    if ($diversity < $minDiversity) {
                        $minDiversity = $diversity;
                    }
                }

                // Quality: negate the penalty score so lower penalty = higher value.
                // Scale by a small factor so diversity dominates when quality is close.
                $qualityValue = -$candidate['score'];

                // Combined value: diversity (primary) + quality (secondary tiebreak).
                // We multiply diversity by 100 to ensure it outweighs small score diffs.
                $combined = ($minDiversity * 100) + $qualityValue;

                if ($combined > $bestCombined) {
                    $bestCombined = $combined;
                    $bestIndex = $idx;
                }
            }

            $selected[] = $remaining[$bestIndex];
            array_splice($remaining, $bestIndex, 1);
        }

        return $selected;
    }

    /**
     * Computes a diversity score between two raw assignment sets.
     *
     * Returns an integer in [0, ∞) where higher means MORE different.
     * Scores are deliberately coarse-grained so that only substantial
     * scheduling differences (different days, time bands, rooms) contribute,
     * not trivial 30-minute shifts.
     *
     * Components:
     *   +4 per weekday that appears in one solution but not the other.
     *   +3 if the dominant time band (morning/midday/afternoon/evening) differs.
     *   +2 per room that appears in one solution but not the other.
     *   +2 if the meeting count (single vs. split) differs.
     *   +1 if the pattern keys differ (e.g., MW vs. TTh vs. days:x-y).
     */
    private function diversity(array $rawA, array $rawB): int
    {
        $daysA = [];
        $daysB = [];
        $roomsA = [];
        $roomsB = [];
        $bandsA = [];
        $bandsB = [];
        $blocksA = 0;
        $blocksB = 0;
        $onlineBlocksA = 0;
        $onlineBlocksB = 0;
        $patternA = [];
        $patternB = [];
        $modesA = [];
        $modesB = [];

        foreach ($rawA as $assignment) {
            if (($assignment['room_id'] ?? null) !== null) {
                $roomsA[] = $assignment['room_id'];
            }
            if (! empty($assignment['preferred_pattern'])) {
                $patternA[] = $assignment['preferred_pattern'];
            }
            foreach ($assignment['blocks'] as $block) {
                $daysA[] = $block['day'];
                $bandsA[] = self::timeBand($block['start_slot']);
                $modesA[] = $block['mode'] ?? $assignment['mode'] ?? 'on-site';
                if (($block['mode'] ?? $assignment['mode'] ?? 'on-site') === 'online') {
                    $onlineBlocksA++;
                }
                $blocksA++;
            }
        }

        foreach ($rawB as $assignment) {
            if (($assignment['room_id'] ?? null) !== null) {
                $roomsB[] = $assignment['room_id'];
            }
            if (! empty($assignment['preferred_pattern'])) {
                $patternB[] = $assignment['preferred_pattern'];
            }
            foreach ($assignment['blocks'] as $block) {
                $daysB[] = $block['day'];
                $bandsB[] = self::timeBand($block['start_slot']);
                $modesB[] = $block['mode'] ?? $assignment['mode'] ?? 'on-site';
                if (($block['mode'] ?? $assignment['mode'] ?? 'on-site') === 'online') {
                    $onlineBlocksB++;
                }
                $blocksB++;
            }
        }

        $daysA = array_unique($daysA);
        $daysB = array_unique($daysB);
        $roomsA = array_unique($roomsA);
        $roomsB = array_unique($roomsB);
        $bandsA = array_unique($bandsA);
        $bandsB = array_unique($bandsB);
        $modesA = array_unique($modesA);
        $modesB = array_unique($modesB);

        $diversity = 0;

        // Day-set symmetric difference (4 pts per distinct day not shared).
        $dayDiff = array_merge(
            array_diff($daysA, $daysB),
            array_diff($daysB, $daysA),
        );
        $diversity += count(array_unique($dayDiff)) * 4;

        // Time-band symmetric difference (3 pts per distinct band not shared).
        $bandDiff = array_merge(
            array_diff($bandsA, $bandsB),
            array_diff($bandsB, $bandsA),
        );
        $diversity += count(array_unique($bandDiff)) * 3;

        // Room symmetric difference (2 pts per room not shared).
        $roomDiff = array_merge(
            array_diff($roomsA, $roomsB),
            array_diff($roomsB, $roomsA),
        );
        $diversity += count(array_unique($roomDiff)) * 2;

        $modeDiff = array_merge(
            array_diff($modesA, $modesB),
            array_diff($modesB, $modesA),
        );
        $diversity += count(array_unique($modeDiff)) * 3;
        $diversity += abs($onlineBlocksA - $onlineBlocksB) * 3;

        // Meeting count difference (2 pts if one is single and the other split).
        if (($blocksA === 1) !== ($blocksB === 1)) {
            $diversity += 2;
        }

        // Pattern key difference (1 pt if the pattern strings differ).
        $patternA = array_unique($patternA);
        $patternB = array_unique($patternB);
        sort($patternA);
        sort($patternB);
        if ($patternA !== $patternB) {
            $diversity += 1;
        }

        return $diversity;
    }

    /**
     * Returns a coarse time-band label for a slot index.
     *
     * Bands (in 30-min slots from 07:00):
     *   morning   → slots  0–5  (07:00–09:30)
     *   midday    → slots  6–11 (10:00–12:30)
     *   afternoon → slots 12–17 (13:00–15:30)
     *   evening   → slots 18–26 (16:00–20:30 with the default window)
     */
    public static function timeBand(int $startSlot): string
    {
        if ($startSlot < 6) {
            return 'morning';
        }

        if ($startSlot < 12) {
            return 'midday';
        }

        if ($startSlot < 18) {
            return 'afternoon';
        }

        return 'evening';
    }
}
