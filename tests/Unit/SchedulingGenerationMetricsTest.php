<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SchedulingGenerationMetricsTest extends TestCase
{
    public function test_metrics_round_trip_and_calculate_pruned_candidates(): void
    {
        $metrics = new SchedulingGenerationMetrics(
            operation: 'section_solver',
            snapshotQueryCount: 11,
            snapshotElapsedMs: 4.25,
            variableCount: 3,
            candidateCountBefore: 120,
            candidateCountAfter: 95,
            prunedByConstraint: ['room_conflict' => 25],
            iterations: 44,
            solverAttempts: 1,
            retryReasons: ['alternate_pattern:failed'],
            elapsedMs: 9.5,
            fallbackUsage: ['room_tba' => 2],
        );

        $restored = SchedulingGenerationMetrics::fromArray($metrics->toArray());

        $this->assertSame(25, $metrics->prunedCandidateCount());
        $this->assertSame($metrics->toArray(), $restored->toArray());
    }

    public function test_metrics_reject_increasing_candidate_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SchedulingGenerationMetrics(
            operation: 'invalid',
            candidateCountBefore: 2,
            candidateCountAfter: 3,
        );
    }
}
