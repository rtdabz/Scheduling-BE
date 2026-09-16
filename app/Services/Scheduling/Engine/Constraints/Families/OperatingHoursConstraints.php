<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * field_evening_window. Kernel counterpart of Rules\OperatingHoursRule, which also
 * holds slot_grid and operating_hours; those two have no kernel version yet.
 */
final class OperatingHoursConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $isFieldPlacement = $row->mode === 'field'
            || SchedulingConstraintPredicates::isFieldCourse($course, $snapshot->fieldCourseCodes);

        if (! $isFieldPlacement
            || (bool) ($snapshot->departmentSettings['field_evening_schedule_enabled'] ?? false)
            || SchedulingPolicy::timeToMinutes($row->endTime) <= SchedulingPolicy::timeToMinutes(SchedulingPolicy::FIELD_DAY_END_TIME)) {
            return [];
        }

        return [ConstraintSupport::violation('field_evening_window', 'Field courses must end by 5:00 PM unless evening field scheduling is enabled for this department.')];
    }
}
