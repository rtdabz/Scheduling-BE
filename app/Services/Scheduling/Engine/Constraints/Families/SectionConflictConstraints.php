<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;

final class SectionConflictConstraints
{
    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $others): array
    {
        $violations = [];

        foreach ($others as $other) {
            if (! ConstraintSupport::sameSemester($row, $other) || ! SchedulingConstraintPredicates::rowOverlaps($row, $other)) {
                continue;
            }

            $context = ConstraintSupport::conflictContext($other);
            $otherSectionId = ConstraintSupport::intValue($other, 'section_id');

            if ($row->sectionId === $otherSectionId) {
                $violations['section_conflict'] ??= ConstraintSupport::violation('section_conflict', 'Section already has an overlapping class.', context: $context);
            }
        }

        return array_values($violations);
    }
}
