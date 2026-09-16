<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;

/** faculty_conflict. Kernel counterpart of Rules\InstructorConflictRule. */
final class InstructorConflictConstraints
{
    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $others): array
    {
        if ($row->facultyId === null) {
            return [];
        }

        foreach ($others as $other) {
            if (ConstraintSupport::sameSemester($row, $other)
                && SchedulingConstraintPredicates::rowOverlaps($row, $other)
                && $row->facultyId === ConstraintSupport::nullableIntValue($other, 'faculty_id')) {
                return [ConstraintSupport::violation(
                    'faculty_conflict',
                    'Faculty is already teaching an overlapping class.',
                    context: ConstraintSupport::conflictContext($other),
                )];
            }
        }

        return [];
    }
}
