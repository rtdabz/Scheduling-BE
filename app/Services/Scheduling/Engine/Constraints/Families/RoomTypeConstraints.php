<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Rules\RoomTypeRule;

/**
 * room_type_match. Kernel counterpart of Rules\RoomTypeRule, whose static
 * mismatch() makes the decision for both; this side only supplies the
 * snapshot's course, room and field-course codes.
 */
final class RoomTypeConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $room = $row->roomId === null ? null : ($snapshot->roomsById[$row->roomId] ?? null);

        if ($row->mode !== 'online' && $row->roomId !== null && ! is_array($room)) {
            // A room the snapshot does not hold is one the department cannot
            // use; RoomAvailabilityConstraints reports that, and its type is
            // unknown, so repeating it here would double the finding.
            return [];
        }

        $mismatch = RoomTypeRule::mismatch($course, $room, $row->mode, $row->meetingType, null, $snapshot->fieldCourseCodes);

        return $mismatch === null ? [] : [ConstraintSupport::violation($mismatch['rule'], $mismatch['message'])];
    }
}
