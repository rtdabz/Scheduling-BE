<?php

namespace App\Services\Scheduling\Schedule;

use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\ScheduleRecommendationPayload;
use App\Services\Scheduling\Domain\SectionScheduleGenerationResult;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers the plans a section preview showed, so selecting one saves exactly
 * what the user compared.
 *
 * `select` used to re-run the solver and pick the same rank from the new run.
 * The solver stops on a wall-clock limit, so under different load the second
 * run could explore differently and "Rank 2" could come back as other rows.
 * Staleness is not this store's concern: accepting a recommendation commits its
 * plan through CommitSchedulePlan, which refuses a plan whose snapshot changed.
 */
final class PreviewedPlanStore
{
    private const TTL_SECONDS = 1800;

    /**
     * @param  array<string, mixed>  $input  the normalized preview input
     * @param  array<string, mixed>  $configurationContract
     */
    public function remember(
        int $userId,
        int $sectionId,
        array $input,
        SectionScheduleGenerationResult $generated,
        array $configurationContract,
    ): void {
        $plansById = [];
        foreach ($generated->plans as $plan) {
            $plansById[$plan->planId] = $plan;
        }

        foreach ($generated->solutions as $solution) {
            $plan = $plansById[$solution['plan_id']] ?? null;
            if (! $plan instanceof SchedulePlan) {
                continue;
            }

            Cache::put($this->key((string) $solution['plan_id']), [
                'user_id' => $userId,
                'section_id' => $sectionId,
                'solution' => $solution,
                'schedule_plan' => $plan->toArray(),
                'input_payload' => ScheduleRecommendationPayload::fromPrepared(
                    $input,
                    $generated->preparedConfiguration,
                    $plan,
                )->toArray(),
                'department_profile' => $generated->profile->value,
                'generation_metrics' => $generated->generationMetrics,
                'configuration_contract' => $configurationContract,
            ], self::TTL_SECONDS);
        }
    }

    /**
     * The previewed plan, when it is still remembered and belongs to this user
     * and section; null means the caller must generate again.
     *
     * @return array{solution: array<string, mixed>, schedule_plan: array<string, mixed>, input_payload: array<string, mixed>, department_profile: string, generation_metrics: array<string, mixed>, configuration_contract: array<string, mixed>}|null
     */
    public function find(string $planId, int $userId, int $sectionId): ?array
    {
        $entry = Cache::get($this->key($planId));

        if (! is_array($entry)
            || (int) ($entry['user_id'] ?? 0) !== $userId
            || (int) ($entry['section_id'] ?? 0) !== $sectionId) {
            return null;
        }

        return $entry;
    }

    private function key(string $planId): string
    {
        return 'scheduling:previewed-plan:'.$planId;
    }
}
