<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations\Providers;

use App\Services\Scheduling\Generation\GenerationDraftReviewer;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationResult;
use App\Services\Scheduling\Recommendations\SessionInterpreter;

final readonly class DraftRecommendationProvider implements RecommendationProvider
{
    public function __construct(private GenerationDraftReviewer $reviewer) {}

    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $result = $this->reviewer->review(...$context->inputs);
        $options = [];
        $sessions = [];
        foreach ($result['issues'] as $issue) {
            foreach ($issue['options'] as $option) {
                $options[] = $option;
                $sessions[$option['id']] = SessionInterpreter::fromRows($option['rows'])->toArray();
            }
        }

        return RecommendationResult::adapt(
            $context, $result, $options, 'affected_course_group', 'verified_group',
            metadata: ['checked_rows' => $result['checked_rows'], 'sessions' => $sessions],
        );
    }
}
