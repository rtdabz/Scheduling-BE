<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Rooms;
use App\Models\Schedule;
use App\Services\Scheduling\Department\DepartmentResourceSlotLimitService;
use Illuminate\Support\Collection;

/**
 * room_availability, room_conflict, room_capacity_conflict,
 * online_capacity_conflict.
 *
 * Whether the room is open for scheduling and free at that time. Field rooms
 * and online delivery use the department's configured concurrency limit rather
 * than a single booking.
 */
final class RoomAvailabilityRule
{
    public function __construct(
        private readonly RuleLookupCache $lookups,
        private readonly DepartmentResourceSlotLimitService $resourceLimits,
    ) {}

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
     * room_conflict / room_capacity_conflict for physical and field rooms.
     * Online delivery has no room and is checked by onlineCapacity().
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
        $day = (string) $attempt['day'];
        $startTime = (string) $attempt['start_time'];
        $endTime = (string) $attempt['end_time'];
        $departmentId = isset($attempt['department_id']) ? (int) $attempt['department_id'] : null;
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $room = $this->lookups->remember('room:'.$roomId, fn () => Rooms::query()->find($roomId));
        $capacity = $this->effectiveCapacity($room, $departmentId);

        // A field room is shared: several classes of one department may use it at once.
        if (($room?->room_type ?? null) === 'field' && $capacity > 1) {
            $overlaps = Schedule::where('room_id', $roomId)
                ->where('semester_id', $attempt['semester_id'])
                ->where('day', $day)
                ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
                ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->with(['course', 'section'])
                ->get();

            if (! $this->exceedsCapacity($overlaps, $startTime, $endTime, $capacity)) {
                return null;
            }

            return [
                'rule' => 'room_capacity_conflict',
                'message' => "{$room->room_code} capacity is full for this department on {$day} from {$startTime} to {$endTime}. "
                    ."Maximum concurrent classes: {$capacity}.",
                'conflicting_schedule_id' => $overlaps->first()?->id,
            ];
        }

        $conflict = Schedule::where('room_id', $roomId)
            ->where('semester_id', $attempt['semester_id'])
            ->where('day', $day)
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['course', 'section'])
            ->first();

        if (! $conflict) {
            return null;
        }

        return [
            'rule' => 'room_conflict',
            'message' => "Room is already booked on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."for {$conflict->course?->course_code} ({$conflict->section?->section_name}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    /**
     * online_capacity_conflict: the department's concurrent online classes.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    public function onlineCapacity(array $attempt): ?array
    {
        if ((string) ($attempt['mode'] ?? 'on-site') !== 'online') {
            return null;
        }

        $day = (string) $attempt['day'];
        $startTime = (string) $attempt['start_time'];
        $endTime = (string) $attempt['end_time'];
        $departmentId = isset($attempt['department_id']) ? (int) $attempt['department_id'] : null;
        $ignoreIds = RuleSupport::ignoreIds($attempt['ignore_schedule_id'] ?? null);

        $overlapCount = Schedule::query()
            ->where('semester_id', (int) $attempt['semester_id'])
            ->where('mode', 'online')
            ->where('day', $day)
            ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->count();

        $onlineLimit = $departmentId !== null ? $this->resourceLimits->online($departmentId) : 1;

        if ($overlapCount < $onlineLimit) {
            return null;
        }

        return [
            'rule' => 'online_capacity_conflict',
            'message' => "Online capacity is full for this department on {$day} from {$startTime} to {$endTime}. Configured concurrent online classes: {$onlineLimit}.",
        ];
    }

    private function exceedsCapacity(Collection $overlaps, string $startTime, string $endTime, int $capacity): bool
    {
        $events = [
            [RuleSupport::timeToMinutes($startTime), 1],
            [RuleSupport::timeToMinutes($endTime), -1],
        ];

        foreach ($overlaps as $schedule) {
            $events[] = [RuleSupport::timeToMinutes((string) $schedule->start_time), 1];
            $events[] = [RuleSupport::timeToMinutes((string) $schedule->end_time), -1];
        }

        usort(
            $events,
            static fn (array $left, array $right): int => ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]),
        );

        $active = 0;
        foreach ($events as [$minute, $delta]) {
            if ($minute === null) {
                continue;
            }

            $active += $delta;
            if ($active > $capacity) {
                return true;
            }
        }

        return false;
    }

    private function effectiveCapacity(?Rooms $room, ?int $departmentId): int
    {
        if (($room?->room_type ?? null) === 'field') {
            return $departmentId !== null
                ? $this->resourceLimits->field($departmentId)
                : (int) ($room?->max_concurrent_classes ?? 1);
        }

        return max(1, (int) ($room?->max_concurrent_classes ?? 1));
    }
}
