<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Rooms;
use App\Models\Schedule;

/**
 * room_availability, room_conflict.
 *
 * Whether the room is open for scheduling and free at that time. A lecture or
 * laboratory room holds one class. Field and online rooms are shared without a
 * limit -- the field is open ground and an online class occupies no space -- so
 * they never conflict on booking.
 */
final class RoomAvailabilityRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

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

    /**
     * room_conflict: another class already holds this lecture or laboratory room.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function booking(array $attempt): ?array
    {
        $roomId = $attempt['room_id'] ?? null;
        if ((string) ($attempt['mode'] ?? 'on-site') === 'online' || $roomId === null) {
            return null;
        }

        $roomId = (int) $roomId;
        $room = $this->lookups->remember('room:'.$roomId, fn () => Rooms::query()->find($roomId));
        if (Rooms::isSharedType($room?->room_type)) {
            return null;
        }

        $day = (string) $attempt['day'];
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $conflicts = Schedule::where('room_id', $roomId)
            ->where('semester_id', $attempt['semester_id'])
            ->where('day', $day)
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', (string) $attempt['end_time'])
            ->where('end_time', '>', (string) $attempt['start_time'])
            ->with(['course', 'section'])
            ->orderBy('start_time')
            ->get();

        if ($conflicts->isEmpty()) {
            return null;
        }

        $conflict = $conflicts->first();
        $more = $conflicts->count() > 1 ? ' and '.($conflicts->count() - 1).' more' : '';

        return [
            'rule' => 'room_conflict',
            'message' => "Room is already booked on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."for {$conflict->course?->course_code} ({$conflict->section?->section_name}){$more}.",
            'conflicting_schedule_id' => $conflict->id,
            'conflicting_schedule_ids' => $conflicts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ];
    }
}
