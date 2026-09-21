<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

/**
 * One conflict between two *persisted* meetings, as the conflict inbox shows it.
 *
 * Conflicts are derived, not stored: there is no `conflict_cases` table and
 * `schedules.status` is never used to mark one resolved. The identity of a
 * conflict is therefore its content -- the rule it breaks and the pair of rows
 * it breaks it between -- which is what `id()` encodes. Two scans of unchanged
 * data produce the same ids, and a scan after a successful resolution no longer
 * produces the id that was resolved. That is the only evidence the server
 * accepts that a conflict is gone (see ResolveScheduleConflict).
 *
 * The pair is always stored low id first so the id does not depend on the order
 * the scan happened to visit the rows in.
 */
final readonly class ScheduleConflictCase
{
    /**
     * What the user may do about each rule, in the order the UI offers them.
     * Every entry is an action ResolveScheduleConflict implements, except
     * `request_override`, which routes to the override endpoint, and
     * `apply_recommendation`, which routes to the existing
     * preview -> select plan_id -> accept contract.
     *
     * @var array<string, list<string>>
     */
    public const RESOLUTION_OPTIONS = [
        BatchConflict::RULE_SECTION => ['move_schedule', 'apply_recommendation'],
        BatchConflict::RULE_ROOM => ['change_room', 'change_delivery_mode', 'move_schedule', 'apply_recommendation'],
        BatchConflict::RULE_FACULTY => ['reassign_instructor', 'move_schedule', 'request_override'],
        BatchConflict::RULE_SUBJECT_SECTION_TIME => ['move_schedule', 'change_delivery_mode', 'apply_recommendation'],
    ];

    public function __construct(
        public string $rule,
        /** Lower of the two schedule ids. */
        public int $scheduleId,
        /** Higher of the two schedule ids. */
        public int $otherScheduleId,
        public int $semesterId,
        public string $day,
        public string $overlapStart,
        public string $overlapEnd,
        /** @var array<string, mixed> the lower row, as the scan read it */
        public array $schedule = [],
        /** @var array<string, mixed> the higher row, as the scan read it */
        public array $otherSchedule = [],
    ) {}

    /**
     * Build from a BatchConflict whose row indexes are schedule ids.
     *
     * @param  array<int, array<string, mixed>>  $rowsById
     */
    public static function fromBatchConflict(BatchConflict $conflict, array $rowsById): self
    {
        $left = (int) $conflict->index;
        $right = (int) $conflict->otherIndex;
        [$low, $high] = $left <= $right ? [$left, $right] : [$right, $left];

        return new self(
            rule: $conflict->rule,
            scheduleId: $low,
            otherScheduleId: $high,
            semesterId: (int) ($rowsById[$low]['semester_id'] ?? 0),
            day: (string) $conflict->day,
            overlapStart: (string) $conflict->overlapStart,
            overlapEnd: (string) $conflict->overlapEnd,
            schedule: $rowsById[$low] ?? [],
            otherSchedule: $rowsById[$high] ?? [],
        );
    }

    /**
     * Readable rather than hashed: the id ends up in audit metadata, and a
     * reviewer reading `room_conflict:42:77` a term later should not need the
     * scan that produced it to know what it meant.
     */
    public function id(): string
    {
        return "{$this->rule}:{$this->scheduleId}:{$this->otherScheduleId}";
    }

    /**
     * The rule and the ordered pair carried by a conflict id, or null when the
     * id is not one this class could have produced.
     *
     * @return array{rule: string, schedule_id: int, other_schedule_id: int}|null
     */
    public static function parseId(string $id): ?array
    {
        $parts = explode(':', $id);
        if (count($parts) !== 3) {
            return null;
        }

        [$rule, $low, $high] = $parts;
        if (! array_key_exists($rule, self::RESOLUTION_OPTIONS)
            || ! ctype_digit($low)
            || ! ctype_digit($high)
            || (int) $low <= 0
            || (int) $high <= (int) $low) {
            return null;
        }

        return ['rule' => $rule, 'schedule_id' => (int) $low, 'other_schedule_id' => (int) $high];
    }

    /** @return list<int> */
    public function scheduleIds(): array
    {
        return [$this->scheduleId, $this->otherScheduleId];
    }

    public function involves(int $scheduleId): bool
    {
        return $scheduleId === $this->scheduleId || $scheduleId === $this->otherScheduleId;
    }

    /** @return list<string> */
    public function resolutionOptions(): array
    {
        return self::RESOLUTION_OPTIONS[$this->rule] ?? [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'rule' => $this->rule,
            'semester_id' => $this->semesterId,
            'day' => $this->day,
            'overlap_start' => $this->overlapStart,
            'overlap_end' => $this->overlapEnd,
            'message' => $this->message(),
            'resolution_options' => $this->resolutionOptions(),
            'schedules' => [$this->schedule, $this->otherSchedule],
        ];
    }

    public function message(): string
    {
        $left = (string) ($this->schedule['course_code'] ?? 'A class');
        $right = (string) ($this->otherSchedule['course_code'] ?? 'another class');
        $window = "{$this->day} {$this->overlapStart}-{$this->overlapEnd}";

        return match ($this->rule) {
            BatchConflict::RULE_SECTION => "{$left} and {$right} are scheduled for the same section on {$window}.",
            BatchConflict::RULE_ROOM => "{$left} and {$right} share the same room on {$window}.",
            BatchConflict::RULE_FACULTY => "The same instructor teaches {$left} and {$right} on {$window}.",
            BatchConflict::RULE_SUBJECT_SECTION_TIME => "{$left} runs online for two sections at once on {$window}.",
            default => "{$left} conflicts with {$right} on {$window}.",
        };
    }
}
