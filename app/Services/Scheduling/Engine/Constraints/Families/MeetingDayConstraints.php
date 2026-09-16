<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * field_day_constraint, minor_day_constraint, forced_course_day.
 * Kernel counterpart of Rules\MeetingDayRule.
 */
final class MeetingDayConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $violations = [];

        // NSTP may use any day, so it is exempt from the category limits below.
        if (! SchedulingConstraintPredicates::isNstpCourse($course)) {
            if (SchedulingConstraintPredicates::isFieldCourse($course, $snapshot->fieldCourseCodes)) {
                if (! in_array($row->day, SchedulingPolicy::WEEKDAYS, true)) {
                    $violations[] = ConstraintSupport::violation('field_day_constraint', 'PATHFIT and other field courses must be scheduled Monday through Friday.');
                }
            } elseif (! SchedulingConstraintPredicates::isMajorCourse($course)
                && ! in_array($row->day, SchedulingPolicy::WEEKDAYS_AND_SATURDAY, true)) {
                $violations[] = ConstraintSupport::violation('minor_day_constraint', 'Minor courses (GEC, GEE, and similar) must be scheduled Monday through Saturday.');
            }
        }

        $forcedDay = $snapshot->forcedDaysByCourseId[$row->courseId] ?? null;
        if (is_string($forcedDay) && $forcedDay !== $row->day) {
            $violations[] = ConstraintSupport::violation(
                'forced_course_day',
                "This course is configured to meet on {$forcedDay}.",
                context: ['required_day' => $forcedDay],
            );
        }

        return $violations;
    }
}
