<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;

/**
 * section_conflict, subject_section_time_conflict.
 * Kernel counterpart of Rules\SectionConflictRule.
 */
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

            if ($row->mode === 'online'
                && ConstraintSupport::stringValue($other, 'mode') === 'online'
                && $row->courseId === ConstraintSupport::intValue($other, 'course_id')
                && $row->sectionId !== $otherSectionId) {
                $violations['subject_section_time_conflict'] ??= ConstraintSupport::violation(
                    'subject_section_time_conflict',
                    'The same online course is already scheduled for another section at this time.',
                    context: $context,
                );
            }
        }

        return array_values($violations);
    }
}
