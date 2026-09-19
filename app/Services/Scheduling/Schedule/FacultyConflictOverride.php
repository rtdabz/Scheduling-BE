<?php

namespace App\Services\Scheduling\Schedule;

use App\Models\Schedule;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * Lets an instructor be assigned over a conflict on purpose.
 *
 * Only the instructor's own clashes can be overridden: being double-booked at
 * that time, or teaching outside a part-timer's availability. Everything else
 * the rule engine reports (inactive instructor, department or program
 * alignment, room and section conflicts) stays a refusal.
 *
 * An override is recorded on the meetings themselves -- the ones assigned and
 * the ones they clash with -- so later saves of either do not raise the same
 * conflict again. It only stands while the meeting keeps the instructor, day
 * and time it was approved with: moving or reassigning it clears the flag (see
 * Schedule::booted), and a meeting that changes is checked afresh.
 */
final class FacultyConflictOverride
{
    public const RULES = ['faculty_conflict', 'part_time_faculty_availability'];

    /** Request flag asking an assignment endpoint to override instructor conflicts. */
    public const REQUEST_FLAG = 'override_conflicts';

    /** @param  array<string, mixed>  $violation */
    public static function isOverridable(array $violation): bool
    {
        return in_array($violation['rule'] ?? null, self::RULES, true);
    }

    /**
     * True when there is something to override and nothing that cannot be.
     *
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
     * The refusal payload, with each violation marked and a top-level flag the
     * client reads to offer "Assign anyway".
     *
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
     * Meetings the assigned instructor clashes with, so they carry the override
     * too and their own later saves stand.
     *
     * @param  list<array<string, mixed>>  $violations
     * @return list<int>
     */
    public static function partnerIds(array $violations): array
    {
        $ids = [];
        foreach ($violations as $violation) {
            // Section and room conflicts also name their clashes; they are never
            // overridden, so their meetings must not be flagged.
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
     * Written with the query builder, after any model update in the same
     * transaction, so the model's clearing hook cannot undo it.
     *
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
     * Whether a persisted override still covers this attempt: the stored meeting
     * is flagged and the attempt keeps its instructor, day and time.
     *
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
     * The persisted meetings, among these candidate rows, whose override still
     * stands -- one query for a whole batch rather than one per row.
     *
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
     * Drops the instructor conflicts a standing override already covers.
     *
     * A double-booking is covered only when every meeting it clashes with was
     * overridden too. Otherwise a clash with a meeting placed later, which
     * nobody approved, would be hidden on the next save of this one.
     *
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
