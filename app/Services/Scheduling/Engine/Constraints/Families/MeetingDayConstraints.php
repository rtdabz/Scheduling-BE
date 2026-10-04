<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Rules\MeetingDayRule;

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

        $sunday = MeetingDayRule::sundayClassesMismatch(
            (bool) ($snapshot->departmentSettings['sunday_classes_enabled'] ?? false),
            $row->day,
        );
        if ($sunday !== null) {
            $violations[] = ConstraintSupport::violation($sunday['rule'], $sunday['message']);
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
