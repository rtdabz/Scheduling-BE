<?php

namespace App\Services\Scheduling\Engine\Rules;

/**
 * room_availability: whether the room is open for scheduling. Booking clashes
 * (room_conflict) live in OverlapConflict.
 */
final class RoomAvailabilityRule
{
    /** room_availability: the room itself is marked available. */
    public function status(AttemptRecords $records): ?array
    {
        $room = $records->room;
        if ($room === null || ($room->status ?? 'available') === 'available') {
            return null;
        }

        return [
            'rule' => 'room_availability',
            'message' => "Room {$room->room_code} is not available for scheduling.",
        ];
    }
}
