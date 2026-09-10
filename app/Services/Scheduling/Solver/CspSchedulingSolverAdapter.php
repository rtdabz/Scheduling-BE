<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Solver;

use App\Services\Scheduling\CspSolver;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;

final class CspSchedulingSolverAdapter implements SchedulingSolver
{
    public function __construct(
        private readonly CspSolver $solver,
        private readonly SolverResultMapper $results,
    ) {}

    public function solve(GenerationConfiguration $configuration, SchedulingSnapshot $snapshot): array
    {
        $this->solver->setInputSnapshot($snapshot);

        return $this->results->mapRanked(
            $this->solver->solveRankedFromSchema($configuration->toArray()),
        );
    }

    public function iterationsUsed(): int
    {
        return $this->solver->iterationsUsed();
    }

    public function searchLimitReached(): bool
    {
        return $this->solver->searchLimitReached();
    }

    public function generationMetrics(): SchedulingGenerationMetrics
    {
        return $this->solver->generationMetrics();
    }
}
