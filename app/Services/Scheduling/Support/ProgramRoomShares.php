<?php

namespace App\Services\Scheduling\Support;

use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Sections;

/**
 * Which program may use each of a department's rooms on each weekday, when the
 * department runs two or more programs.
 *
 * Programs generate their timetables separately, so first-come would hand the
 * best slots to whoever generated first. Instead the week is divided before
 * anyone generates, equally and the same way every time:
 *
 *  - a home room (home_first / strict) belongs to its program every day;
 *  - every other room -- every room under `open` -- is divided by whole days,
 *    the programs taking turns and the starting program rotating from one room
 *    to the next, so each program gets the same mix of days and rooms.
 *
 * Whole days rather than mornings and afternoons: many classes cross noon (a
 * 10 AM-1 PM laboratory), and a noon boundary would shut them out of every
 * divided room. Sunday is an overflow day and stays open to every program.
 *
 * Another program may use a day it does not own only once the owner is done --
 * every active section of the owner has a saved schedule this semester -- and
 * never under `strict`. The generator does not borrow on its own; borrowing is
 * a deliberate manual placement.
 *
 * The validator, the snapshot (and so the generator and the constraint kernel)
 * all read this one class, so none of them can allow a placement another refuses.
 */
class ProgramRoomShares
{
    /** The days divided between programs. */
    public const DIVIDED_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * Each divided room's owner per day for a department in a semester, keyed
     * by room id then day. Empty when the department has fewer than two programs.
     *
     * @return array<int, array<string, array{program_id: int, program_code: string, lendable: bool, borrowable: bool}>>
     */
    public function forDepartment(int $departmentId, int $semesterId): array
    {
        $department = Departments::query()->find($departmentId);
        if ($department === null) {
            return [];
        }

        $programs = Program::query()
            ->where('department_id', $departmentId)
            ->orderBy('id')
            ->get(['id', 'code']);
        if ($programs->count() < 2) {
            return [];
        }

        $policy = (string) ($department->room_sharing_policy ?: Departments::ROOM_SHARING_OPEN);
        $programIds = $programs->pluck('id')->map('intval')->all();
        $codes = $programs->mapWithKeys(static fn (Program $program): array => [(int) $program->id => (string) $program->code])->all();

        $rooms = Rooms::query()
            ->where('department_id', $departmentId)
            ->whereNotIn('room_type', Rooms::SHARED_ROOM_TYPES)
            ->orderBy('room_code')
            ->orderBy('id')
            ->get(['id', 'home_program_id'])
            ->map(static fn (Rooms $room): array => [
                'id' => (int) $room->id,
                'home_program_id' => $room->home_program_id === null ? null : (int) $room->home_program_id,
            ])
            ->all();

        $lendable = $policy !== Departments::ROOM_SHARING_STRICT;
        $done = $lendable ? $this->donePrograms($departmentId, $semesterId, $programIds) : [];

        $shares = [];
        foreach (self::ownersByDay($policy, $rooms, $programIds) as $roomId => $owners) {
            foreach ($owners as $day => $programId) {
                $shares[$roomId][$day] = [
                    'program_id' => $programId,
                    'program_code' => $codes[$programId] ?? (string) $programId,
                    'lendable' => $lendable,
                    'borrowable' => $lendable && in_array($programId, $done, true),
                ];
            }
        }

        return $shares;
    }

    /**
     * The owning program of each divided room per day. Pure, so the division
     * can be read and tested without a database.
     *
     * @param  list<array{id: int, home_program_id: int|null}>  $rooms  in a stable order
     * @param  list<int>  $programIds  in a stable order
     * @return array<int, array<string, int>>
     */
    public static function ownersByDay(string $policy, array $rooms, array $programIds): array
    {
        $count = count($programIds);
        if ($count < 2) {
            return [];
        }

        $owners = [];
        $dividedRoom = 0;
        foreach ($rooms as $room) {
            $home = $room['home_program_id'];
            if ($policy !== Departments::ROOM_SHARING_OPEN && $home !== null && in_array($home, $programIds, true)) {
                $owners[$room['id']] = array_fill_keys(self::DIVIDED_DAYS, $home);

                continue;
            }

            foreach (self::DIVIDED_DAYS as $index => $day) {
                $owners[$room['id']][$day] = $programIds[($index + $dividedRoom) % $count];
            }
            $dividedRoom++;
        }

        return $owners;
    }

    /**
     * Why a section of `$programId` may not use this room on this day, or null
     * when it may.
     *
     * @param  array<string, array{program_id: int, program_code: string, lendable: bool, borrowable: bool}>|null  $roomShares
     */
    public static function refusal(?array $roomShares, ?int $programId, string $day, string $roomCode): ?string
    {
        $share = $roomShares[$day] ?? null;
        if ($share === null || $programId === null || (int) $share['program_id'] === $programId || ($share['borrowable'] ?? false)) {
            return null;
        }

        $owner = $share['program_code'];

        return ($share['lendable'] ?? false)
            ? "Room {$roomCode} is {$owner}'s on {$day}. Another program can use it that day only after {$owner} has saved schedules for all its sections."
            : "Room {$roomCode} is {$owner}'s on {$day}. Your department keeps each program to its own rooms and days.";
    }

    /**
     * Programs whose every active section this semester already has a saved
     * schedule; a program with no active section needs no room and counts too.
     *
     * @param  list<int>  $programIds
     * @return list<int>
     */
    private function donePrograms(int $departmentId, int $semesterId, array $programIds): array
    {
        $waiting = Sections::query()
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->where('status', 'active')
            ->whereIn('program_id', $programIds)
            ->whereDoesntHave('schedules')
            ->distinct()
            ->pluck('program_id')
            ->map('intval')
            ->all();

        return array_values(array_diff($programIds, $waiting));
    }
}
