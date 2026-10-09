<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations\Providers;

use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationResult;
use App\Services\Scheduling\Schedule\ConflictRecommender;

final readonly class ConflictRecommendationProvider implements RecommendationProvider
{
    public function __construct(private ConflictRecommender $recommender) {}

    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $options = $this->recommender->recommend(...$context->inputs);

        // The controller still applies action-specific authorization and final ranking.
        return RecommendationResult::adapt($context, $options, $options, 'affected_meeting_group', 'verified_group');
    }
}
