<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Services\Scheduling\Domain\ScheduleCandidate;

final class SolverResultMapper
{
    /**
     * @param  list<array<string, mixed>>  $rankedSolutions
     * @return list<ScheduleCandidate>
     */
    public function mapRanked(array $rankedSolutions): array
    {
        return array_map(
            static fn (array $solution): ScheduleCandidate => ScheduleCandidate::fromArray($solution),
            $rankedSolutions,
        );
    }
}
