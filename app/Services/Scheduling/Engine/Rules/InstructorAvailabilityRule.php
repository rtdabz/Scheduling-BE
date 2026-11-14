<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Services\Scheduling\Support\SchedulingPolicy;

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
        if (self::outsideAvailability(
            $faculty->employment_type,
            $faculty->availabilities()->get(),
            $day,
            (string) ($attempt['start_time'] ?? '00:00'),
            (string) ($attempt['end_time'] ?? '00:00'),
        )) {
            $violations[] = self::violation($day);
        }

        return $violations;
    }

    /**
     * True when the meeting is not fully covered by the instructor's default
     * hours plus added windows for that day. Shared by the rule engine, the
     * snapshot kernel and the availability-change impact check.
     *
     * @param  iterable<array<string, mixed>|object>  $addedWindows
     */
    public static function outsideAvailability(?string $employmentType, iterable $addedWindows, string $day, string $startTime, string $endTime): bool
    {
        $dayIndex = self::DAY_INDEX[$day] ?? null;
        if ($dayIndex === null) {
            return false;
        }

        return ! self::coveredContinuously(
            SchedulingPolicy::facultyDayWindows($employmentType, $addedWindows, $dayIndex),
            SchedulingPolicy::normalizeTime($startTime),
            SchedulingPolicy::normalizeTime($endTime),
        );
    }

    /** @return array{rule: string, message: string} */
    public static function violation(string $day): array
    {
        return [
            'rule' => 'part_time_faculty_availability',
            'message' => 'The selected assignment falls outside the instructor\'s availability for '.$day
                .' (default hours for their employment type plus any added windows).',
        ];
    }

    /**
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
