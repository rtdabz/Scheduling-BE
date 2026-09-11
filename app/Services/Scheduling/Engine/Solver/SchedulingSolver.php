<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\ScheduleCandidate;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;

interface SchedulingSolver
{
    /** @return list<ScheduleCandidate> */
    /**
     * Solve only against the caller-provided immutable snapshot. Snapshot
     * capture belongs to the application boundary, never to the solver.
     */
    public function solve(GenerationConfiguration $configuration, SchedulingSnapshot $snapshot): array;

    public function iterationsUsed(): int;

    public function searchLimitReached(): bool;

    public function generationMetrics(): SchedulingGenerationMetrics;
}
