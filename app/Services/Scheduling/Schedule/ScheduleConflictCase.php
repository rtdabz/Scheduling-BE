<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

final readonly class ScheduleConflictCase
{
    /**
     * @var array<string, list<string>>
     */
    public const RESOLUTION_OPTIONS = [
        BatchConflict::RULE_SECTION => ['move_schedule'],
        BatchConflict::RULE_ROOM => ['change_room', 'change_delivery_mode', 'move_schedule'],
        BatchConflict::RULE_FACULTY => ['reassign_instructor', 'move_schedule', 'request_override'],
        BatchConflict::RULE_SUBJECT_SECTION_TIME => ['move_schedule', 'change_delivery_mode'],
    ];

    public function __construct(
        public string $rule,
        public int $scheduleId,
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

    public function id(): string
    {
        return "{$this->rule}:{$this->scheduleId}:{$this->otherScheduleId}";
    }

    /**
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

    /**
     * @return array{department_ids: list<int>, section_ids: list<int>}
     */
    public function owners(): array
    {
        $ids = fn (string $key): array => array_values(array_unique(array_filter([
            (int) ($this->schedule[$key] ?? 0),
            (int) ($this->otherSchedule[$key] ?? 0),
        ])));

        return ['department_ids' => $ids('department_id'), 'section_ids' => $ids('section_id')];
    }

    /**
     * @return array<string, mixed>
     */
    public function toResolutionRecord(): array
    {
        return [
            'id' => $this->id(),
            'rule' => $this->rule,
            'message' => $this->message(),
            'day' => $this->day,
            'overlap_start' => $this->overlapStart,
            'overlap_end' => $this->overlapEnd,
            ...$this->owners(),
        ];
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
