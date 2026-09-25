<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\CspSolver;

final class CspYearLevelSchedulingSolverAdapter implements YearLevelSchedulingSolver
{
    public function __construct(private readonly CspSolver $solver) {}

    public function beginGenerationContext(): void
    {
        $this->solver->beginGenerationContext();
    }

    public function setInputSnapshot(SchedulingSnapshot $snapshot): void
    {
        $this->solver->setInputSnapshot($snapshot);
    }

    public function solveRankedFromSchema(array $input): array
    {
        return $this->solver->solveRankedFromSchema($input);
    }

    public function iterationsUsed(): int
    {
        return $this->solver->iterationsUsed();
    }

    public function searchLimitReached(): bool
    {
        return $this->solver->searchLimitReached();
    }

    public function deadEndsByCourseId(): array
    {
        return $this->solver->deadEndsByCourseId();
    }

    public function generationMetrics(): SchedulingGenerationMetrics
    {
        return $this->solver->generationMetrics();
    }

    public function departmentRoomFairness(): array
    {
        return $this->solver->departmentRoomFairness();
    }

    public function generationForcedDaysByCourseId(): array
    {
        return $this->solver->generationForcedDaysByCourseId();
    }
}
