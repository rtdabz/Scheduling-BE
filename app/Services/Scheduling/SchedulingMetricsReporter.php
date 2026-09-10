<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use Illuminate\Support\Facades\Log;

final class SchedulingMetricsReporter
{
    /** @param array<string, mixed> $context */
    public function report(SchedulingGenerationMetrics $metrics, array $context = []): array
    {
        $payload = $metrics->toArray();

        if ((bool) config('app.performance_logging', false)) {
            Log::info('scheduling_generation_metrics', [
                ...$context,
                ...$payload,
            ]);
        }

        return $payload;
    }
}
