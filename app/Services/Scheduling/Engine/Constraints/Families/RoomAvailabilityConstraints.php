<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Support\ProgramRoomShares;
use App\Services\Scheduling\Support\RoomAccessPolicy;

final class RoomAvailabilityConstraints
{
    /** @return list<ConstraintViolation> */
    public function forRow(ScheduleRow $row, SchedulingSnapshot $snapshot): array
    {
        if ($row->roomId === null) {
            return [];
        }

        $room = $snapshot->roomsById[$row->roomId] ?? null;
        if (! is_array($room)) {
            return [ConstraintSupport::violation(
                'room_department_alignment',
                'Selected room is not shared and does not belong to the selected section department.',
                context: ['room_id' => $row->roomId],
            )];
        }

        $violations = [];

        $access = $this->roomAccess($row, $room) ?? $this->programShare($row, $room, $snapshot);
        if ($access !== null) {
            $violations[] = $access;
        }

        if ((string) ($room['status'] ?? 'available') !== 'available') {
            $violations[] = ConstraintSupport::violation(
                'room_availability',
                'Room '.($room['room_code'] ?? $row->roomId).' is not available for scheduling.',
                context: ['room_id' => $row->roomId],
            );
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function roomAccess(ScheduleRow $row, array $room): ?ConstraintViolation
    {
        $ownerId = $room['department_id'] ?? null;
        if ($ownerId === null) {
            return null;
        }

        if ((int) $ownerId === $row->departmentId) {
            $lent = RoomAccessPolicy::overlappingWindow(
                self::withMinutes((array) ($room['lent_windows'] ?? [])),
                $row->day,
                $row->startTime,
                $row->endTime,
            );

            return $lent === null ? null : ConstraintSupport::violation(
                'room_department_alignment',
                RoomAccessPolicy::lentRefusal((string) ($room['room_code'] ?? $row->roomId), $lent),
                context: ['room_id' => $row->roomId],
            );
        }

        $windows = $room['grant_windows'] ?? null;
        if (! is_array($windows)) {
            return ConstraintSupport::violation(
                'room_department_alignment',
                'Selected room is not shared and does not belong to the selected section department.',
                context: ['room_id' => $row->roomId],
            );
        }

        $windows = self::withMinutes($windows);

        if (RoomAccessPolicy::fitsWindows($windows, $row->day, $row->startTime, $row->endTime)) {
            return null;
        }

        return ConstraintSupport::violation(
            'room_department_alignment',
            'Room '.($room['room_code'] ?? $row->roomId).' is granted to your department only on '.RoomAccessPolicy::describe($windows).'.',
            context: ['room_id' => $row->roomId],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $windows
     * @return list<array<string, mixed>>
     */
    private static function withMinutes(array $windows): array
    {
        return array_values(array_map(static fn (array $window): array => $window + [
            'start_minutes' => RoomAccessPolicy::minutes((string) $window['start_time']),
            'end_minutes' => RoomAccessPolicy::minutes((string) $window['end_time']),
        ], $windows));
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function programShare(ScheduleRow $row, array $room, SchedulingSnapshot $snapshot): ?ConstraintViolation
    {
        if (! is_array($room['program_days'] ?? null) || (int) ($room['department_id'] ?? 0) !== $row->departmentId) {
            return null;
        }

        $programId = $snapshot->sectionsById[$row->sectionId]['program_id'] ?? null;
        $message = ProgramRoomShares::refusal(
            $room['program_days'],
            $programId === null ? null : (int) $programId,
            $row->day,
            (string) ($room['room_code'] ?? $row->roomId),
        );

        return $message === null ? null : ConstraintSupport::violation(
            'room_department_alignment',
            $message,
            context: ['room_id' => $row->roomId],
        );
    }
}
