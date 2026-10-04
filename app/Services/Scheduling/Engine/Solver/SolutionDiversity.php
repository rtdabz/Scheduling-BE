<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Services\Scheduling\Support\SchedulingPolicy;

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
     * @param  array<int, array{rank: int, score: int, schedules: array, _raw: array}>  $scored
     * @return array<int, array{rank: int, score: int, schedules: array, _raw: array}>
     */
    public function selectDiverse(array $scored, int $limit): array
    {
        if ($scored === [] || $limit <= 0) {
            return [];
        }

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

        $selected[] = array_shift($remaining);

        while (count($selected) < $limit && $remaining !== []) {
            $bestIndex = 0;
            $bestCombined = PHP_INT_MIN;

            foreach ($remaining as $idx => $candidate) {
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

                $qualityValue = -$candidate['score'];

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

        $dayDiff = array_merge(
            array_diff($daysA, $daysB),
            array_diff($daysB, $daysA),
        );
        $diversity += count(array_unique($dayDiff)) * 4;

        $bandDiff = array_merge(
            array_diff($bandsA, $bandsB),
            array_diff($bandsB, $bandsA),
        );
        $diversity += count(array_unique($bandDiff)) * 3;

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

        if (($blocksA === 1) !== ($blocksB === 1)) {
            $diversity += 2;
        }

        $patternA = array_unique($patternA);
        $patternB = array_unique($patternB);
        sort($patternA);
        sort($patternB);
        if ($patternA !== $patternB) {
            $diversity += 1;
        }

        return $diversity;
    }

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
