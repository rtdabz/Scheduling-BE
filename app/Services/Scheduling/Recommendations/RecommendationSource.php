<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

enum RecommendationSource: string
{
    case ManualPlacement = 'manual_placement';
    case DraftReview = 'draft_review';
    case Configuration = 'generation_configuration';
    case Feasibility = 'generation_feasibility';
    case Search = 'generation_search';
    case PreferredDays = 'preferred_days';
    case Conflict = 'conflict_resolution';
    case LegacySplit = 'legacy_split_validation';
}
