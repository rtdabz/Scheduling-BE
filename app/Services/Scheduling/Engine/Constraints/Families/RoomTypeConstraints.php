<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Rules\RoomTypeRule;

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
            return [];
        }

        $mismatch = RoomTypeRule::mismatch($course, $room, $row->mode, $row->meetingType, $snapshot->departmentId, $snapshot->fieldCourseCodes);

        return $mismatch === null ? [] : [ConstraintSupport::violation($mismatch['rule'], $mismatch['message'])];
    }
}
