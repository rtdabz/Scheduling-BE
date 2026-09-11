<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Models\Sections;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\SchedulePlanStatus;
use App\Services\Scheduling\Domain\SectionScheduleGenerationResult;

/**
 * Production section-generation boundary.
 *
 * Request normalization remains an HTTP concern for compatibility, while all
 * scheduling decisions flow through GenerateSchedulePlan.
 */
final class GenerateSectionSchedulePlans
{
    public function __construct(
        private readonly ScheduleGenerationPreflightService $preflight,
        private readonly ScheduleRequirementBuilderResolver $requirementBuilders,
        private readonly PrepareGenerationConfigurationForSolve $prepareConfiguration,
        private readonly GenerateSchedulePlan $planner,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function generate(Sections $section, array $input): SectionScheduleGenerationResult
    {
        $courseIds = array_values(array_unique(array_map('intval', $input['course_ids'] ?? [])));
        $profile = $this->preflight->validate($section, $courseIds, $input);
        $input['requirements_by_course_id'] = $this->requirementBuilders->build($section, $courseIds, $input);
        $prepared = $this->prepareConfiguration->prepareLegacy(
            (int) $section->term_id,
            (int) $section->department_id,
            $input,
        );

        $plans = $this->planner->generate(
            termId: (int) $section->term_id,
            departmentId: (int) $section->department_id,
            configuration: $prepared->configuration,
            configurationWarningsConfirmed: true,
        );

        return new SectionScheduleGenerationResult(
            profile: $profile,
            preparedConfiguration: $prepared,
            plans: $plans,
            solutions: $this->legacySolutions($plans),
            generationMetrics: $this->generationMetrics($plans),
        );
    }

    /** @param list<SchedulePlan> $plans */
    private function legacySolutions(array $plans): array
    {
        $solutions = [];
        foreach ($plans as $index => $plan) {
            if ($plan->status === SchedulePlanStatus::Invalid || $plan->rows === []) {
                continue;
            }

            $solutions[] = [
                'plan_id' => $plan->planId,
                'rank' => (int) ($plan->metadata['rank'] ?? ($index + 1)),
                'score' => (int) ($plan->scores['quality_score'] ?? 0),
                'schedules' => array_map(
                    static fn ($row): array => $row->toArray(),
                    $plan->rows,
                ),
            ];
        }

        usort($solutions, static fn (array $left, array $right): int => $left['rank'] <=> $right['rank']);

        return $solutions;
    }

    /** @param list<SchedulePlan> $plans */
    private function generationMetrics(array $plans): array
    {
        foreach ($plans as $plan) {
            $metrics = $plan->metadata['generation_metrics'] ?? null;
            if (is_array($metrics)) {
                return $metrics;
            }
        }

        return [];
    }
}
