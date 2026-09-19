<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Models\Rooms;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Support\RoomAccessPolicy;

/**
 * room_availability, room_conflict. Kernel counterpart of
 * Rules\RoomAvailabilityRule: the room must be marked available, and a lecture
 * or laboratory room holds one class; field and online are shared without a
 * limit.
 */
final class RoomAvailabilityConstraints
{
    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $others, SchedulingSnapshot $snapshot): array
    {
        if ($row->roomId === null) {
            return [];
        }

        // The snapshot holds every room the department may use (own, shared,
        // granted) plus any already booked, so a room missing from it is one
        // the department has no access to. Skipping it let such a row through.
        $room = $snapshot->roomsById[$row->roomId] ?? null;
        if (! is_array($room)) {
            return [ConstraintSupport::violation(
                'room_department_alignment',
                'Selected room is not shared and does not belong to the selected section department.',
                context: ['room_id' => $row->roomId],
            )];
        }

        $violations = [];

        $access = $this->roomAccess($row, $room);
        if ($access !== null) {
            $violations[] = $access;
        }

        // A preview captured before a room was closed must not commit into it.
        if ((string) ($room['status'] ?? 'available') !== 'available') {
            $violations[] = ConstraintSupport::violation(
                'room_availability',
                'Room '.($room['room_code'] ?? $row->roomId).' is not available for scheduling.',
                context: ['room_id' => $row->roomId],
            );
        }

        if ($row->mode === 'online' || Rooms::isSharedType((string) ($room['room_type'] ?? ''))) {
            return $violations;
        }

        // Any row holding the room occupies it, whatever its mode. Online saves
        // clear room_id, so an online row that still has one is legacy data;
        // RuleEngine and the solver's index both count it, and so does this.
        foreach ($others as $other) {
            if (ConstraintSupport::sameSemester($row, $other)
                && SchedulingConstraintPredicates::rowOverlaps($row, $other)
                && $row->roomId === ConstraintSupport::nullableIntValue($other, 'room_id')) {
                $violations[] = ConstraintSupport::violation('room_conflict', 'Room is already booked for an overlapping class.', context: ConstraintSupport::conflictContext($other));

                break;
            }
        }

        return $violations;
    }

    /**
     * room_department_alignment. Mirrors DepartmentAssignmentRule: another
     * department's room is usable only inside the windows granted to this
     * one, which the snapshot carries on the room record.
     *
     * @param  array<string, mixed>  $room
     */
    private function roomAccess(ScheduleRow $row, array $room): ?ConstraintViolation
    {
        $ownerId = $room['department_id'] ?? null;
        if ($ownerId === null || (int) $ownerId === $row->departmentId) {
            return null;
        }

        $windows = $room['grant_windows'] ?? null;
        if (! is_array($windows)) {
            return ConstraintSupport::violation(
                'room_department_alignment',
                'Selected room is not shared and does not belong to the selected section department.',
                context: ['room_id' => $row->roomId],
            );
        }

        $windows = array_map(static fn (array $window): array => $window + [
            'start_minutes' => RoomAccessPolicy::minutes((string) $window['start_time']),
            'end_minutes' => RoomAccessPolicy::minutes((string) $window['end_time']),
        ], $windows);

        if (RoomAccessPolicy::fitsWindows($windows, $row->day, $row->startTime, $row->endTime)) {
            return null;
        }

        return ConstraintSupport::violation(
            'room_department_alignment',
            'Room '.($room['room_code'] ?? $row->roomId).' is granted to your department only on '.RoomAccessPolicy::describe($windows).'.',
            context: ['room_id' => $row->roomId],
        );
    }
}
