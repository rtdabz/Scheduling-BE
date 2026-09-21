<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Engine\Rules\DeliveryModeRule;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * hybrid_mode, hybrid_eligibility, hybrid_component_type, hybrid_component_shape.
 * Kernel counterpart of Rules\DeliveryModeRule, whose static checks make each
 * decision; this side supplies the snapshot's course.
 */
final class DeliveryModeConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $findings = [];

        if ($row->mode === 'field' && $row->isHybrid) {
            $findings[] = ['rule' => 'hybrid_mode', 'message' => 'Field schedules cannot be marked as hybrid.'];
        } elseif ($row->isHybrid) {
            $findings[] = DeliveryModeRule::hybridShapeMismatch(
                $course,
                $row->mode,
                $row->meetingType,
                SchedulingPolicy::timeToMinutes($row->endTime) - SchedulingPolicy::timeToMinutes($row->startTime),
            );
        }

        return array_values(array_map(
            static fn (array $finding): ConstraintViolation => ConstraintSupport::violation($finding['rule'], $finding['message']),
            array_filter($findings),
        ));
    }
}
