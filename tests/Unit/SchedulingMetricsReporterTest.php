<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use App\Services\Scheduling\Support\SchedulingMetricsReporter;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SchedulingMetricsReporterTest extends TestCase
{
    public function test_reporter_emits_structured_event_when_performance_logging_is_enabled(): void
    {
        config()->set('app.performance_logging', true);
        Log::spy();

        $payload = app(SchedulingMetricsReporter::class)->report(
            new SchedulingGenerationMetrics(
                operation: 'test_generation',
                iterations: 12,
                solverAttempts: 1,
            ),
            ['run_id' => 'run-1'],
        );

        $this->assertSame(12, $payload['iterations']);
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(static fn (string $event, array $context): bool => $event === 'scheduling_generation_metrics'
                && $context['run_id'] === 'run-1'
                && $context['iterations'] === 12);
    }
}
