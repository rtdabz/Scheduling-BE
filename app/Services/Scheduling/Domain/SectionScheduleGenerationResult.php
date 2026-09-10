<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use App\Enums\DepartmentSchedulingProfile;

final readonly class SectionScheduleGenerationResult
{
    /**
     * @param  list<SchedulePlan>  $plans
     * @param  list<array<string, mixed>>  $solutions
     * @param  array<string, mixed>  $generationMetrics
     */
    public function __construct(
        public DepartmentSchedulingProfile $profile,
        public PreparedGenerationConfiguration $preparedConfiguration,
        public array $plans,
        public array $solutions,
        public array $generationMetrics = [],
    ) {}
}
