<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

interface RecommendationProvider
{
    public function recommend(RecommendationContext $context): RecommendationResult;
}
