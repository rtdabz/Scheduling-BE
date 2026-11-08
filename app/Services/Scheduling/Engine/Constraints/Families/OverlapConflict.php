<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Models\Rooms;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Support\ConflictMessage;

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
                $violations['room_conflict'] ??= ConstraintSupport::violation('room_conflict', ConflictMessage::room(
                    isset($room['room_code']) ? (string) $room['room_code'] : null,
                    $row->day,
                    $row->startTime,
                    $row->endTime,
                    $this->booking($other, $snapshot),
                ), context: $context);
            }

            if ($row->facultyId !== null && $row->facultyId === ConstraintSupport::nullableIntValue($other, 'faculty_id')) {
                $faculty = $snapshot->facultiesById[$row->facultyId] ?? [];
                $violations['faculty_conflict'] ??= ConstraintSupport::violation('faculty_conflict', ConflictMessage::instructor(
                    trim(($faculty['first_name'] ?? '').' '.($faculty['last_name'] ?? '')),
                    $row->day,
                    $row->startTime,
                    $row->endTime,
                    $this->booking($other, $snapshot),
                ), context: $context);
            }

            if ($row->sectionId === $otherSectionId) {
                $violations['section_conflict'] ??= ConstraintSupport::violation('section_conflict', ConflictMessage::section(
                    $this->sectionName($row->sectionId, $snapshot),
                    $row->day,
                    $row->startTime,
                    $row->endTime,
                    $this->booking($other, $snapshot),
                ), context: $context);
            }

            if ($row->mode === 'online'
                && ConstraintSupport::stringValue($other, 'mode') === 'online'
                && $row->courseId === ConstraintSupport::intValue($other, 'course_id')
                && $row->sectionId !== $otherSectionId) {
                $violations['subject_section_time_conflict'] ??= ConstraintSupport::violation(
                    'subject_section_time_conflict',
                    ConflictMessage::onlineCourse(
                        $this->courseCode($row->courseId, $snapshot),
                        $row->day,
                        $row->startTime,
                        $row->endTime,
                        $this->booking($other, $snapshot),
                    ),
                    context: $context,
                );
            }
        }

        return array_values($violations);
    }

    /**
     * Names come from the snapshot; department codes are not captured there, so the clause omits them.
     *
     * @param  array<string, mixed>|ScheduleRow  $other
     * @return array{course: ?string, section: ?string, start: string, end: string}
     */
    private function booking(array|ScheduleRow $other, SchedulingSnapshot $snapshot): array
    {
        return [
            'course' => $this->courseCode(ConstraintSupport::intValue($other, 'course_id'), $snapshot),
            'section' => $this->sectionName(ConstraintSupport::intValue($other, 'section_id'), $snapshot),
            'start' => $other instanceof ScheduleRow ? $other->startTime : (string) ($other['start_time'] ?? ''),
            'end' => $other instanceof ScheduleRow ? $other->endTime : (string) ($other['end_time'] ?? ''),
        ];
    }

    private function courseCode(int $courseId, SchedulingSnapshot $snapshot): ?string
    {
        $code = $snapshot->coursesById[$courseId]['course_code'] ?? null;

        return $code === null ? null : (string) $code;
    }

    private function sectionName(int $sectionId, SchedulingSnapshot $snapshot): ?string
    {
        $name = $snapshot->sectionsById[$sectionId]['section_name'] ?? null;

        return $name === null ? null : (string) $name;
    }
}
