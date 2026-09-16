<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Services\Scheduling\Support\SchedulingPolicy;

/** Small parsing helpers the rule classes share. */
final class RuleSupport
{
    /** Minutes since midnight, or null when the value is not a valid HH:MM time. */
    public static function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', SchedulingPolicy::normalizeTime($time));

        if (count($parts) < 2) {
            return null;
        }

        if (! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    /** Length of a start/end pair in minutes; an unparsable time counts as zero. */
    public static function durationMinutes(string $startTime, string $endTime): int
    {
        return (int) (self::timeToMinutes($endTime) ?? 0) - (int) (self::timeToMinutes($startTime) ?? 0);
    }

    /**
     * The schedule ids an update must not collide with: itself, or every row of
     * a batch being moved together.
     *
     * @return list<int>
     */
    public static function ignoreIds(int|array|null $ignoreScheduleId): array
    {
        if ($ignoreScheduleId === null) {
            return [];
        }

        $ids = is_array($ignoreScheduleId) ? $ignoreScheduleId : [$ignoreScheduleId];

        return array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        ));
    }

    /** @param array<string, mixed> $attempt */
    public static function courseId(array $attempt): int
    {
        return (int) ($attempt['course_id'] ?? $attempt['subject_id'] ?? 0);
    }
}
