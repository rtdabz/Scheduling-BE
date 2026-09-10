<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Solver;

use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Domain\SchedulingSnapshot;

interface YearLevelSchedulingSolver
{
    public function beginGenerationContext(): void;

    public function setInputSnapshot(SchedulingSnapshot $snapshot): void;

    /** @param array<string,mixed> $input @return list<array<string,mixed>> */
    public function solveRankedFromSchema(array $input): array;

    public function iterationsUsed(): int;

    public function searchLimitReached(): bool;

    public function generationMetrics(): SchedulingGenerationMetrics;

    /** @return array<string,mixed> */
    public function departmentRoomFairness(): array;

    /** @return array<int,string> */
    public function generationForcedDaysByCourseId(): array;
}
