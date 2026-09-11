<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints;

use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;

final class SchedulingConstraintEvaluationContextFactory
{
    public function forGeneration(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
    ): SchedulingConstraintEvaluationContext {
        $rows = [];
        $ignoreIds = [];

        foreach ($configuration->tentativeSchedules as $schedule) {
            if (! is_array($schedule)) {
                continue;
            }

            $rows[] = ScheduleRow::fromArray($schedule);
            $id = (int) ($schedule['id'] ?? 0);
            if ($id > 0) {
                $ignoreIds[$id] = $id;
            }
        }

        foreach ($snapshot->persistedSchedules as $schedule) {
            if ((int) ($schedule['section_id'] ?? 0) !== $configuration->sectionId
                || ! in_array((int) ($schedule['course_id'] ?? 0), $configuration->courseIds, true)
                || ! in_array((string) ($schedule['status'] ?? ''), ['draft', 'completed', 'revision'], true)) {
                continue;
            }

            $id = (int) ($schedule['id'] ?? 0);
            if ($id > 0) {
                $ignoreIds[$id] = $id;
            }
        }

        return new SchedulingConstraintEvaluationContext($rows, array_values($ignoreIds));
    }
}
