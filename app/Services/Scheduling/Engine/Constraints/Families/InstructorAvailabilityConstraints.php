<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Rules\InstructorAvailabilityRule;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * faculty_active, part_time_faculty_availability. Kernel counterpart of
 * Rules\InstructorAvailabilityRule, reading the instructor from the snapshot.
 * A snapshot captured without faculties cannot judge this and reports nothing.
 */
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

        $dayIndex = InstructorAvailabilityRule::DAY_INDEX[$row->day] ?? null;
        if (($faculty['employment_type'] ?? null) === 'part-time' && $dayIndex !== null) {
            $windows = [];
            foreach ((array) ($faculty['availabilities'] ?? []) as $window) {
                if ((int) ($window['day_index'] ?? -1) === $dayIndex) {
                    $windows[] = [
                        SchedulingPolicy::normalizeTime((string) $window['start_time']),
                        SchedulingPolicy::normalizeTime((string) $window['end_time']),
                    ];
                }
            }

            $start = SchedulingPolicy::normalizeTime($row->startTime);
            $end = SchedulingPolicy::normalizeTime($row->endTime);

            if (! InstructorAvailabilityRule::coveredContinuously($windows, $start, $end)) {
                $violations[] = ConstraintSupport::violation(
                    'part_time_faculty_availability',
                    'The selected assignment falls outside the instructor\'s availability window for '.$row->day.'.',
                    context: [...$context, 'day' => $row->day, 'start_time' => $start],
                );
            }
        }

        return $violations;
    }
}
