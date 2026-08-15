<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Rooms;
use App\Models\Sections;

/** Coordinates post-CSP evaluation without moving generation into the scorer. */
class ScheduleCandidateOptimizer
{
    public function __construct(
        private readonly CSPSolver $solver,
        private readonly ScheduleQualityEvaluator $evaluator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return list<array<string, mixed>>
     */
    public function rank(array $candidates, array $sections, array $configsBySectionId = []): array
    {
        if ($candidates === []) {
            return [];
        }

        $roomTypesById = Rooms::query()
            ->pluck('room_type', 'id')
            ->mapWithKeys(static fn (string $type, int|string $id): array => [(int) $id => $type])
            ->all();

        return $this->evaluator->rank(
            $candidates,
            $sections,
            $configsBySectionId,
            $this->solver->departmentRoomFairness(),
            $roomTypesById,
        );
    }

    public function rankForSection(array $candidates, Sections $section, array $config = []): array
    {
        $config['forced_days_by_course_id'] = $this->solver->generationForcedDaysByCourseId();

        return $this->rank($candidates, [$section], [(int) $section->id => $config]);
    }
}
