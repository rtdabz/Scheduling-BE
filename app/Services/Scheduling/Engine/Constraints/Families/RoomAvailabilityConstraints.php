<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;

/** online_capacity_conflict, room_conflict, room_capacity_conflict. Kernel counterpart of Rules\RoomAvailabilityRule. */
final class RoomAvailabilityConstraints
{
    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $others, SchedulingSnapshot $snapshot): array
    {
        $violations = [];

        if ($row->mode === 'online') {
            $matches = $this->matchingRows($row, $others, static fn (ScheduleRow $target, array|ScheduleRow $other): bool => $target->departmentId === ConstraintSupport::intValue($other, 'department_id')
                && ConstraintSupport::stringValue($other, 'mode') === 'online');
            $capacity = max(1, (int) ($snapshot->resourceLimits['online'] ?? 1));
            if (SchedulingConstraintPredicates::concurrencyExceeds($row, $matches, $capacity)) {
                $violations[] = ConstraintSupport::violation('online_capacity_conflict', 'Online capacity is full for this department and time window.', context: ['capacity' => $capacity]);
            }

            return $violations;
        }

        if ($row->roomId === null || ! isset($snapshot->roomsById[$row->roomId])) {
            return [];
        }

        $room = $snapshot->roomsById[$row->roomId];
        $roomType = (string) ($room['room_type'] ?? '');
        $capacity = $roomType === 'field'
            ? max(1, (int) ($snapshot->resourceLimits['field'] ?? 1))
            : max(1, (int) ($room['max_concurrent_classes'] ?? 1));
        $departmentScoped = $roomType === 'field' || $capacity > 1;

        $matches = $this->matchingRows($row, $others, static fn (ScheduleRow $target, array|ScheduleRow $other): bool => $target->roomId === ConstraintSupport::nullableIntValue($other, 'room_id')
            && ConstraintSupport::stringValue($other, 'mode') !== 'online'
            && (! $departmentScoped || $target->departmentId === ConstraintSupport::intValue($other, 'department_id')));

        if ($capacity <= 1 && $matches !== []) {
            $violations[] = ConstraintSupport::violation('room_conflict', 'Room is already booked for an overlapping class.', context: ConstraintSupport::conflictContext($matches[0]));
        } elseif ($matches !== [] && SchedulingConstraintPredicates::concurrencyExceeds($row, $matches, $capacity)) {
            $violations[] = ConstraintSupport::violation('room_capacity_conflict', 'Room capacity is full for this department and time window.', context: ['capacity' => $capacity]);
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @param  callable(ScheduleRow, array<string, mixed>|ScheduleRow): bool  $predicate
     * @return list<array<string, mixed>|ScheduleRow>
     */
    private function matchingRows(ScheduleRow $row, array $others, callable $predicate): array
    {
        return array_values(array_filter(
            $others,
            static fn (array|ScheduleRow $other): bool => ConstraintSupport::sameSemester($row, $other)
                && SchedulingConstraintPredicates::rowOverlaps($row, $other)
                && $predicate($row, $other),
        ));
    }
}
