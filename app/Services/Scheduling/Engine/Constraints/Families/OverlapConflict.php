<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Models\Rooms;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;

final class OverlapConflict
{
    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $others, SchedulingSnapshot $snapshot): array
    {
        $violations = [];

        $room = $row->roomId === null ? null : ($snapshot->roomsById[$row->roomId] ?? null);
        $roomHeld = is_array($room)
            && $row->mode !== 'online'
            && ! Rooms::isSharedType((string) ($room['room_type'] ?? ''));

        foreach ($others as $other) {
            if (! ConstraintSupport::sameSemester($row, $other) || ! SchedulingConstraintPredicates::rowOverlaps($row, $other)) {
                continue;
            }

            $context = ConstraintSupport::conflictContext($other);
            $otherSectionId = ConstraintSupport::intValue($other, 'section_id');

            if ($roomHeld && $row->roomId === ConstraintSupport::nullableIntValue($other, 'room_id')) {
                $violations['room_conflict'] ??= ConstraintSupport::violation('room_conflict', 'Room is already booked for an overlapping class.', context: $context);
            }

            if ($row->facultyId !== null && $row->facultyId === ConstraintSupport::nullableIntValue($other, 'faculty_id')) {
                $violations['faculty_conflict'] ??= ConstraintSupport::violation('faculty_conflict', 'Instructor is already teaching an overlapping class.', context: $context);
            }

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
