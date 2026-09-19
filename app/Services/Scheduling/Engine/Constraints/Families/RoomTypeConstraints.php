<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;

/** room_type_match. Kernel counterpart of Rules\RoomTypeRule. */
final class RoomTypeConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $room = $row->roomId === null ? null : ($snapshot->roomsById[$row->roomId] ?? null);
        $requiredType = SchedulingConstraintPredicates::effectiveRoomType($course, $snapshot->fieldCourseCodes, $row->meetingType);

        if ($row->mode === 'online') {
            return SchedulingConstraintPredicates::allowsOnline($course, $snapshot->fieldCourseCodes, $row->meetingType)
                ? []
                : [ConstraintSupport::violation('room_type_match', 'This course component cannot use online delivery for its room requirement.')];
        }

        if ($row->roomId !== null && ! is_array($room)) {
            // A room the snapshot does not hold is one the department cannot
            // use; RoomAvailabilityConstraints reports that, and its type is
            // unknown, so repeating it here would double the finding.
            return [];
        }

        if (! is_array($room)) {
            return $row->mode === 'on-site'
                && SchedulingConstraintPredicates::allowsRoomTba($course, $snapshot->fieldCourseCodes, $row->meetingType)
                    ? []
                    : [ConstraintSupport::violation('room_type_match', 'A physical room is required for this schedule.')];
        }

        $roomType = (string) ($room['room_type'] ?? '');
        if ($row->mode === 'field') {
            return $roomType === 'field'
                ? []
                : [ConstraintSupport::violation('room_type_match', 'Field schedules must use a field room assignment.')];
        }

        if (in_array($roomType, ['online', 'field'], true)) {
            return [ConstraintSupport::violation('room_type_match', 'On-site schedules require a physical lecture or laboratory room.')];
        }

        if ($requiredType === 'laboratory' && $roomType !== 'laboratory') {
            return [ConstraintSupport::violation('room_type_match', 'This course component requires a laboratory room.')];
        }

        if ($requiredType === 'lecture' && $roomType === 'laboratory'
            && ! SchedulingConstraintPredicates::canUseLaboratoryForLecture($course, $room)) {
            return [ConstraintSupport::violation('room_type_match', 'This course can only use lecture-capable laboratory rooms as a fallback.')];
        }

        if ($requiredType === 'lecture' && in_array($roomType, ['lecture', 'laboratory'], true)) {
            return [];
        }

        return $requiredType === $roomType
            ? []
            : [ConstraintSupport::violation('room_type_match', "This course requires a {$requiredType} room.")];
    }
}
