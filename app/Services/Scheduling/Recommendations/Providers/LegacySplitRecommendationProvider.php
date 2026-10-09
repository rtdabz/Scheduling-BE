<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations\Providers;

use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationResult;

/** Retains controller-owned split preview computation while shared batch helpers still depend on it. */
final readonly class LegacySplitRecommendationProvider implements RecommendationProvider
{
    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $payload = $context->inputs['payload'];

        return RecommendationResult::adapt(
            $context, $payload, $payload['operations'] ?? [], 'legacy_split_preview',
            metadata: ['status' => $payload['status']],
        );
    }
}
