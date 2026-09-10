<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use App\Services\Scheduling\ScheduleRequirement;
use InvalidArgumentException;

final readonly class MeetingGroup implements SchedulingContract
{
    /**
     * @param  list<ScheduleRequirement>  $requirements
     * @param  list<ScheduleRow>  $rows
     */
    public function __construct(
        public string $groupId,
        public int $sectionId,
        public int $courseId,
        public string $type,
        public array $requirements,
        public array $rows = [],
    ) {
        if ($this->groupId === '' || $this->sectionId <= 0 || $this->courseId <= 0) {
            throw new InvalidArgumentException('Meeting group identity is incomplete.');
        }

        if (! in_array($this->type, ['single', 'multi_day', 'hybrid', 'minor_split'], true)) {
            throw new InvalidArgumentException('Unsupported meeting group type.');
        }

        if ($this->requirements === []) {
            throw new InvalidArgumentException('A meeting group requires at least one meeting requirement.');
        }

        foreach ($this->requirements as $requirement) {
            if (! $requirement instanceof ScheduleRequirement) {
                throw new InvalidArgumentException('Meeting group requirements must be ScheduleRequirement instances.');
            }

            if ($requirement->courseId !== $this->courseId) {
                throw new InvalidArgumentException('Meeting requirements must belong to the meeting group course.');
            }
        }

        foreach ($this->rows as $row) {
            if (! $row instanceof ScheduleRow || $row->sectionId !== $this->sectionId || $row->courseId !== $this->courseId) {
                throw new InvalidArgumentException('Meeting rows must belong to the meeting group section and course.');
            }
        }
    }

    public function isComplete(): bool
    {
        return count($this->rows) === count($this->requirements);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            groupId: (string) ($payload['group_id'] ?? $payload['split_group_id'] ?? ''),
            sectionId: (int) ($payload['section_id'] ?? 0),
            courseId: (int) ($payload['course_id'] ?? $payload['subject_id'] ?? 0),
            type: (string) ($payload['type'] ?? 'single'),
            requirements: array_map(
                static fn (array $requirement): ScheduleRequirement => ScheduleRequirement::fromArray($requirement),
                array_values(is_array($payload['requirements'] ?? null) ? $payload['requirements'] : []),
            ),
            rows: array_map(
                static fn (array $row): ScheduleRow => ScheduleRow::fromArray($row),
                array_values(is_array($payload['rows'] ?? null) ? $payload['rows'] : []),
            ),
        );
    }

    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'section_id' => $this->sectionId,
            'course_id' => $this->courseId,
            'type' => $this->type,
            'requirements' => array_map(
                static fn (ScheduleRequirement $requirement): array => $requirement->toArray(),
                $this->requirements,
            ),
            'rows' => array_map(static fn (ScheduleRow $row): array => $row->toArray(), $this->rows),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
