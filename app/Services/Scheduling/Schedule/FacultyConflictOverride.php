<?php

namespace App\Services\Scheduling\Schedule;

use App\Models\Schedule;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class FacultyConflictOverride
{
    public const RULES = ['faculty_conflict', 'part_time_faculty_availability'];

    public const REQUEST_FLAG = 'override_conflicts';

    /** @param  array<string, mixed>  $violation */
    public static function isOverridable(array $violation): bool
    {
        return in_array($violation['rule'] ?? null, self::RULES, true);
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     */
    public static function onlyOverridable(array $violations): bool
    {
        if ($violations === []) {
            return false;
        }

        foreach ($violations as $violation) {
            if (! self::isOverridable($violation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, mixed>
     */
    public static function refusal(string $message, array $violations): array
    {
        return [
            'message' => $message,
            'violations' => array_map(
                static fn (array $violation): array => $violation + ['overridable' => self::isOverridable($violation)],
                $violations,
            ),
            'can_override_conflicts' => self::onlyOverridable($violations),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     * @return list<int>
     */
    public static function partnerIds(array $violations): array
    {
        $ids = [];
        foreach ($violations as $violation) {
            if (! self::isOverridable($violation)) {
                continue;
            }

            foreach ((array) ($violation['conflicting_schedule_ids'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
            if (isset($violation['conflicting_schedule_id'])) {
                $ids[] = (int) $violation['conflicting_schedule_id'];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  list<int>  $scheduleIds
     */
    public static function flag(array $scheduleIds): void
    {
        $scheduleIds = array_values(array_unique(array_map('intval', $scheduleIds)));
        if ($scheduleIds === []) {
            return;
        }

        Schedule::query()->whereIn('id', $scheduleIds)->update(['faculty_conflict_override' => true]);
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    public static function standsFor(array $attempt): bool
    {
        $scheduleId = (int) ($attempt['id'] ?? 0);
        $facultyId = (int) ($attempt['faculty_id'] ?? 0);
        if ($scheduleId <= 0 || $facultyId <= 0) {
            return false;
        }

        $stored = Schedule::query()
            ->whereKey($scheduleId)
            ->where('faculty_conflict_override', true)
            ->first(['id', 'faculty_id', 'day', 'start_time', 'end_time']);

        return $stored !== null
            && (int) $stored->faculty_id === $facultyId
            && (string) $stored->day === (string) ($attempt['day'] ?? '')
            && SchedulingPolicy::normalizeTime((string) $stored->start_time) === SchedulingPolicy::normalizeTime((string) ($attempt['start_time'] ?? ''))
            && SchedulingPolicy::normalizeTime((string) $stored->end_time) === SchedulingPolicy::normalizeTime((string) ($attempt['end_time'] ?? ''));
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<int, true>
     */
    public static function standingIds(iterable $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && (int) ($row['faculty_id'] ?? 0) > 0) {
                $candidates[$id] = $row;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $standing = [];
        Schedule::query()
            ->whereIn('id', array_keys($candidates))
            ->where('faculty_conflict_override', true)
            ->get(['id', 'faculty_id', 'day', 'start_time', 'end_time'])
            ->each(static function (Schedule $stored) use ($candidates, &$standing): void {
                $row = $candidates[(int) $stored->id];
                if (
                    (int) $stored->faculty_id === (int) $row['faculty_id']
                    && (string) $stored->day === (string) ($row['day'] ?? '')
                    && SchedulingPolicy::normalizeTime((string) $stored->start_time) === SchedulingPolicy::normalizeTime((string) ($row['start_time'] ?? ''))
                    && SchedulingPolicy::normalizeTime((string) $stored->end_time) === SchedulingPolicy::normalizeTime((string) ($row['end_time'] ?? ''))
                ) {
                    $standing[(int) $stored->id] = true;
                }
            });

        return $standing;
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @param  list<array<string, mixed>>  $violations
     * @return list<array<string, mixed>>
     */
    public static function withoutStanding(array $attempt, array $violations): array
    {
        if ($violations === [] || ! array_filter($violations, [self::class, 'isOverridable']) || ! self::standsFor($attempt)) {
            return $violations;
        }

        $partnerIds = self::partnerIds(array_values(array_filter(
            $violations,
            static fn (array $violation): bool => ($violation['rule'] ?? null) === 'faculty_conflict',
        )));
        $flaggedPartners = $partnerIds === []
            ? []
            : array_flip(Schedule::query()
                ->whereIn('id', $partnerIds)
                ->where('faculty_conflict_override', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all());

        return array_values(array_filter($violations, static function (array $violation) use ($flaggedPartners): bool {
            if (! self::isOverridable($violation)) {
                return true;
            }

            if (($violation['rule'] ?? null) !== 'faculty_conflict') {
                return false;
            }

            foreach (self::partnerIds([$violation]) as $partnerId) {
                if (! isset($flaggedPartners[$partnerId])) {
                    return true;
                }
            }

            return false;
        }));
    }
}
