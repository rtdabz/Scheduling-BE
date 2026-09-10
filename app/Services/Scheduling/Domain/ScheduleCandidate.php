<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class ScheduleCandidate implements SchedulingContract
{
    /**
     * @param  list<ScheduleRow>  $rows
     * @param  array<string, int|float>  $scoreBreakdown
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $rows,
        public int|float|null $qualityScore = null,
        public int|float|null $penaltyScore = null,
        public array $scoreBreakdown = [],
        public array $metadata = [],
    ) {
        if ($this->rows === []) {
            throw new InvalidArgumentException('A schedule candidate must contain at least one row.');
        }

        foreach ($this->rows as $row) {
            if (! $row instanceof ScheduleRow) {
                throw new InvalidArgumentException('Schedule candidate rows must be ScheduleRow instances.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $rows = array_map(
            static fn (array $row): ScheduleRow => ScheduleRow::fromArray($row),
            array_values(is_array($payload['schedules'] ?? null) ? $payload['schedules'] : ($payload['rows'] ?? [])),
        );

        $known = ['schedules', 'rows', 'score', 'quality_score', 'penalty_score', 'score_breakdown'];
        $metadata = array_diff_key($payload, array_flip($known));

        return new self(
            rows: $rows,
            qualityScore: $payload['quality_score'] ?? $payload['score'] ?? null,
            penaltyScore: $payload['penalty_score'] ?? null,
            scoreBreakdown: is_array($payload['score_breakdown'] ?? null) ? $payload['score_breakdown'] : [],
            metadata: $metadata,
        );
    }

    public function hasUnresolvedRooms(): bool
    {
        foreach ($this->rows as $row) {
            if (! $row->isRoomResolved()) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return array_merge($this->metadata, [
            'quality_score' => $this->qualityScore,
            'penalty_score' => $this->penaltyScore,
            'score_breakdown' => $this->scoreBreakdown,
            'schedules' => array_map(static fn (ScheduleRow $row): array => $row->toArray(), $this->rows),
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
