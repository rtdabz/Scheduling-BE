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
    private const DAY_INDEX = [
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

            // The meeting must fit completely inside at least one window that day.
            $fits = $faculty->availabilities()
                ->where('day_index', $dayIndex)
                ->get()
                ->contains(static fn ($window): bool => $start >= SchedulingPolicy::normalizeTime($window->start_time)
                    && $end <= SchedulingPolicy::normalizeTime($window->end_time));

            if (! $fits) {
                $violations[] = [
                    'rule' => 'part_time_faculty_availability',
                    'message' => 'The selected assignment falls outside the instructor\'s availability window for '.$day.'.',
                ];
            }
        }

        return $violations;
    }
}
