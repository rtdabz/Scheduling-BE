<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * faculty_active, part_time_faculty_availability.
 *
 * Whether the chosen instructor can teach at all, and — for part-time
 * instructors — whether the meeting falls inside one of their availability
 * windows. Full-time instructors have no windows to check.
 */
final class InstructorAvailabilityRule
{
    public const DAY_INDEX = [
        'Monday' => 0,
        'Tuesday' => 1,
        'Wednesday' => 2,
        'Thursday' => 3,
        'Friday' => 4,
        'Saturday' => 5,
        'Sunday' => 6,
    ];

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    public function check(array $attempt, AttemptRecords $records): array
    {
        $faculty = $records->faculty;
        if ($faculty === null) {
            return [];
        }

        $violations = [];

        if (($faculty->status ?? 'active') !== 'active') {
            $violations[] = [
                'rule' => 'faculty_active',
                'message' => 'Selected faculty member is inactive and cannot be assigned.',
            ];
        }

        $day = (string) ($attempt['day'] ?? '');
        $dayIndex = self::DAY_INDEX[$day] ?? null;

        if ($faculty->employment_type === 'part-time' && $dayIndex !== null) {
            $start = SchedulingPolicy::normalizeTime((string) ($attempt['start_time'] ?? '00:00'));
            $end = SchedulingPolicy::normalizeTime((string) ($attempt['end_time'] ?? '00:00'));

            $windows = $faculty->availabilities()
                ->where('day_index', $dayIndex)
                ->get()
                ->map(static fn ($window): array => [
                    SchedulingPolicy::normalizeTime((string) $window->start_time),
                    SchedulingPolicy::normalizeTime((string) $window->end_time),
                ])
                ->all();

            if (! self::coveredContinuously($windows, $start, $end)) {
                $violations[] = [
                    'rule' => 'part_time_faculty_availability',
                    'message' => 'The selected assignment falls outside the instructor\'s availability window for '.$day.'.',
                ];
            }
        }

        return $violations;
    }

    /**
     * The meeting must be covered from start to end with no gap. Back-to-back
     * windows (08:00-10:00, 10:00-12:00) count as one, so a 09:00-11:00 class
     * inside them fits; requiring a single window refused it.
     *
     * @param  list<array{0: string, 1: string}>  $windows  normalized HH:MM:SS pairs
     */
    public static function coveredContinuously(array $windows, string $start, string $end): bool
    {
        usort($windows, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $coveredUntil = $start;
        foreach ($windows as [$windowStart, $windowEnd]) {
            if ($windowStart > $coveredUntil) {
                break;
            }

            if ($windowEnd > $coveredUntil) {
                $coveredUntil = $windowEnd;
            }

            if ($coveredUntil >= $end) {
                return true;
            }
        }

        return false;
    }
}
