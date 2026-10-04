<?php

namespace App\Services\Scheduling\Engine\Rules;

final class RoomAvailabilityRule
{
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
