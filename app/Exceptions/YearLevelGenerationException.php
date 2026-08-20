<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a year-level generation run cannot produce a valid timetable.
 *
 * Unlike a bare RuntimeException this carries the diagnostic report the
 * Recommended Adjustment UI needs: which section/course is the bottleneck, what
 * the generator detected as the cause, every retry strategy that was tried, and
 * the concrete adjustments the user can apply before retrying.
 */
class YearLevelGenerationException extends RuntimeException
{
    public const STAGE_FEASIBILITY = 'feasibility';

    public const STAGE_SEARCH = 'search';

    /**
     * @param  list<array<string, mixed>>  $blockingConstraints
     * @param  array<string, mixed>|null  $bottleneck
     * @param  list<array<string, mixed>>  $attempts
     * @param  list<array<string, mixed>>  $recommendations
     */
    public function __construct(
        string $message,
        private readonly string $stage,
        private readonly array $blockingConstraints = [],
        private readonly ?array $bottleneck = null,
        private readonly array $attempts = [],
        private readonly array $recommendations = [],
    ) {
        parent::__construct($message);
    }

    public function stage(): string
    {
        return $this->stage;
    }

    /** @return list<array<string, mixed>> */
    public function blockingConstraints(): array
    {
        return $this->blockingConstraints;
    }

    /** @return array<string, mixed>|null */
    public function bottleneck(): ?array
    {
        return $this->bottleneck;
    }

    /** @return list<array<string, mixed>> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /** @return list<array<string, mixed>> */
    public function recommendations(): array
    {
        return $this->recommendations;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'error_code' => 'year_level_generation_failed',
            'message' => $this->getMessage(),
            'stage' => $this->stage,
            'blocking_constraints' => $this->blockingConstraints,
            'bottleneck' => $this->bottleneck,
            'attempts' => $this->attempts,
            'recommendations' => $this->recommendations,
        ];
    }
}
