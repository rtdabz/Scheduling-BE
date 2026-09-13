<?php

namespace App\Services\Scheduling\Support;

use App\Models\RoomRequest;
use Illuminate\Support\Facades\DB;

/**
 * Which rooms a department may schedule into, and when.
 *
 * A department always reaches its own rooms and the shared ones (no owner).
 * A room owned by another department is reachable only through an approved
 * room request, and then only inside the weekly windows the VPAA approved for
 * that semester.
 *
 * Every placement path -- the validator, the generator's room domain and the
 * alternative-room search -- asks this class, so none of them can offer a room
 * another would refuse.
 */
class RoomAccessPolicy
{
    /**
     * Approved grant windows for a department in a semester, keyed by room id.
     *
     * @return array<int, list<array{day: string, start_time: string, end_time: string, start_minutes: int, end_minutes: int}>>
     */
    public function grantWindowsFor(int $departmentId, int $semesterId): array
    {
        if ($departmentId <= 0 || $semesterId <= 0) {
            return [];
        }

        $rows = DB::table('room_request_windows')
            ->join('room_requests', 'room_requests.id', '=', 'room_request_windows.room_request_id')
            ->where('room_requests.requesting_department_id', $departmentId)
            ->where('room_requests.semester_id', $semesterId)
            ->where('room_requests.status', RoomRequest::STATUS_APPROVED)
            ->orderBy('room_requests.room_id')
            ->orderBy('room_request_windows.day')
            ->orderBy('room_request_windows.start_time')
            ->get([
                'room_requests.room_id',
                'room_request_windows.day',
                'room_request_windows.start_time',
                'room_request_windows.end_time',
            ]);

        $windows = [];
        foreach ($rows as $row) {
            $windows[(int) $row->room_id][] = self::window(
                (string) $row->day,
                (string) $row->start_time,
                (string) $row->end_time,
            );
        }

        return $windows;
    }

    /**
     * Rooms with at least one approved window for the department in the semester.
     *
     * @return list<int>
     */
    public function grantedRoomIds(int $departmentId, ?int $semesterId): array
    {
        if ($departmentId <= 0 || $semesterId === null || $semesterId <= 0) {
            return [];
        }

        return DB::table('room_requests')
            ->where('requesting_department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->where('status', RoomRequest::STATUS_APPROVED)
            ->distinct()
            ->orderBy('room_id')
            ->pluck('room_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Narrows a rooms query to what the department may reach: its own rooms,
     * shared rooms, and rooms granted to it for the semester. Whether a granted
     * room fits a particular meeting is still the RuleEngine's call.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function scopeReachableRooms($query, int $departmentId, ?int $semesterId, string $column = 'department_id', string $idColumn = 'id'): void
    {
        $grantedRoomIds = $this->grantedRoomIds($departmentId, $semesterId);

        $query->where(static function ($scope) use ($departmentId, $grantedRoomIds, $column, $idColumn): void {
            $scope->whereNull($column)->orWhere($column, $departmentId);
            if ($grantedRoomIds !== []) {
                $scope->orWhereIn($idColumn, $grantedRoomIds);
            }
        });
    }

    /**
     * @return array{day: string, start_time: string, end_time: string, start_minutes: int, end_minutes: int}
     */
    public static function window(string $day, string $startTime, string $endTime): array
    {
        $start = SchedulingPolicy::normalizeTime(substr($startTime, 0, 8));
        $end = SchedulingPolicy::normalizeTime(substr($endTime, 0, 8));

        return [
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'start_minutes' => self::minutes($start),
            'end_minutes' => self::minutes($end),
        ];
    }

    /**
     * True when the meeting sits entirely inside one granted window.
     *
     * @param  list<array{day: string, start_minutes: int, end_minutes: int}>  $windows
     */
    public static function fitsWindows(array $windows, string $day, string $startTime, string $endTime): bool
    {
        $start = self::minutes($startTime);
        $end = self::minutes($endTime);

        foreach ($windows as $window) {
            if ($window['day'] === $day && $window['start_minutes'] <= $start && $end <= $window['end_minutes']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parts of each day that fall outside the granted windows, as
     * minute ranges. The generator books these as occupied so it never builds
     * a candidate the validator would refuse.
     *
     * @param  list<array{day: string, start_minutes: int, end_minutes: int}>  $windows
     * @param  list<string>  $days
     * @return array<string, list<array{start_minutes: int, end_minutes: int}>>
     */
    public static function blockedRanges(array $windows, array $days): array
    {
        $blocked = [];
        foreach ($days as $day) {
            $open = array_values(array_filter($windows, static fn (array $window): bool => $window['day'] === $day));
            usort($open, static fn (array $a, array $b): int => $a['start_minutes'] <=> $b['start_minutes']);

            $cursor = 0;
            $ranges = [];
            foreach ($open as $window) {
                if ($window['start_minutes'] > $cursor) {
                    $ranges[] = ['start_minutes' => $cursor, 'end_minutes' => $window['start_minutes']];
                }
                $cursor = max($cursor, $window['end_minutes']);
            }
            if ($cursor < 24 * 60) {
                $ranges[] = ['start_minutes' => $cursor, 'end_minutes' => 24 * 60];
            }

            $blocked[$day] = $ranges;
        }

        return $blocked;
    }

    /** @param list<array{day: string, start_time: string, end_time: string}> $windows */
    public static function describe(array $windows): string
    {
        return implode(', ', array_map(
            static fn (array $window): string => sprintf(
                '%s %s-%s',
                substr($window['day'], 0, 3),
                substr($window['start_time'], 0, 5),
                substr($window['end_time'], 0, 5),
            ),
            $windows,
        ));
    }

    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', array_pad(explode(':', $time), 2, '0'));

        return ($hours * 60) + $minutes;
    }
}
