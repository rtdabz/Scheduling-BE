<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Engine\Rules\MeetingDayRule;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * preferred_pattern, field_day_constraint, minor_day_constraint,
 * forced_course_day. Kernel counterpart of Rules\MeetingDayRule;
 * preferred_pattern runs that rule's own static check. valid_day needs no
 * kernel version: ScheduleRow refuses an unsupported day when it is built.
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

        $pattern = MeetingDayRule::preferredPattern($row->day, $row->preferredPattern);
        if ($pattern !== null) {
            $violations[] = ConstraintSupport::violation($pattern['rule'], $pattern['message']);
        }

        $categoryDay = MeetingDayRule::categoryDay(
            $course,
            $row->day,
            SchedulingConstraintPredicates::isFieldCourse($course, $snapshot->fieldCourseCodes),
        );
        if ($categoryDay !== null) {
            $violations[] = ConstraintSupport::violation($categoryDay['rule'], $categoryDay['message']);
        }

        $forcedDay = $snapshot->forcedDaysByCourseId[$row->courseId] ?? null;
        $forced = MeetingDayRule::forcedDayMismatch(is_string($forcedDay) ? $forcedDay : null, $row->day);
        if ($forced !== null) {
            $violations[] = ConstraintSupport::violation(
                $forced['rule'],
                $forced['message'],
                context: ['required_day' => $forced['required_day']],
            );
        }

        return $violations;
    }
}
