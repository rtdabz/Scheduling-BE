<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Rules\InstructorAvailabilityRule;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class InstructorAvailabilityConstraints
{
    /** @return list<ConstraintViolation> */
    public function forRow(ScheduleRow $row, SchedulingSnapshot $snapshot): array
    {
        $faculty = $row->facultyId === null ? null : ($snapshot->facultiesById[$row->facultyId] ?? null);
        if (! is_array($faculty)) {
            return [];
        }

        $violations = [];
        $context = ['faculty_id' => $row->facultyId];

        if ((string) ($faculty['status'] ?? 'active') !== 'active') {
            $violations[] = ConstraintSupport::violation(
                'faculty_active',
                'Selected faculty member is inactive and cannot be assigned.',
                context: $context,
            );
        }

        // Default hours of the employment type plus added windows, the same
        // check the rule engine runs (InstructorAvailabilityRule::outsideAvailability).
        if (InstructorAvailabilityRule::outsideAvailability(
            isset($faculty['employment_type']) ? (string) $faculty['employment_type'] : null,
            (array) ($faculty['availabilities'] ?? []),
            $row->day,
            $row->startTime,
            $row->endTime,
        )) {
            $violation = InstructorAvailabilityRule::violation($row->day);
            $violations[] = ConstraintSupport::violation(
                $violation['rule'],
                $violation['message'],
                context: [...$context, 'day' => $row->day, 'start_time' => SchedulingPolicy::normalizeTime($row->startTime)],
            );
        }

        return $violations;
    }
}
