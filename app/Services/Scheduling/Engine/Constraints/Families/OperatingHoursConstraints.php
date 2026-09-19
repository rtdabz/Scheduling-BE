<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Engine\Rules\OperatingHoursRule;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * slot_grid, operating_hours, field_evening_window. Kernel counterpart of
 * Rules\OperatingHoursRule. slot_grid and operating_hours run that rule's own
 * static checks against the hours pinned in the snapshot, so a preview is
 * judged by the hours it was generated under and there is one implementation.
 */
final class OperatingHoursConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $violations = [];
        $opening = $snapshot->operatingHours['opening_time'] ?? null;
        $closing = $snapshot->operatingHours['closing_time'] ?? null;

        // The kernel never reads live settings. Every captured snapshot pins
        // the hours; one built without them cannot judge the window, as one
        // built without faculties cannot judge instructor availability.
        if (is_string($opening) && is_string($closing)) {
            // A time that is unreadable, backwards or off the grid makes the
            // window checks meaningless, so it is the only finding reported.
            $grid = OperatingHoursRule::slotGrid($row->startTime, $row->endTime, $opening);
            if ($grid !== null) {
                return [ConstraintSupport::violation($grid['rule'], $grid['message'])];
            }

            $window = OperatingHoursRule::withinOperatingHours($row->startTime, $row->endTime, $opening, $closing);
            if ($window !== null) {
                $violations[] = ConstraintSupport::violation($window['rule'], $window['message']);
            }
        }

        $isFieldPlacement = $row->mode === 'field'
            || SchedulingConstraintPredicates::isFieldCourse($course, $snapshot->fieldCourseCodes);

        if (! $isFieldPlacement) {
            return $violations;
        }

        // The snapshot pins the field end time it was captured with.
        $fieldEnd = (string) ($snapshot->operatingHours['field_end_time'] ?? SchedulingPolicy::fieldDayEndTime());
        $evening = OperatingHoursRule::fieldEveningMismatch($row->endTime, $fieldEnd);
        if ($evening !== null) {
            $violations[] = ConstraintSupport::violation($evening['rule'], $evening['message']);
        }

        return $violations;
    }
}
