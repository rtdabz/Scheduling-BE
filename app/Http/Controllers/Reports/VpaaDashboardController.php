<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Semester;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class VpaaDashboardController extends Controller
{
    private const LIVE_STATUSES = SchedulingPolicy::VPAA_VISIBLE_STATUSES;

    public function __invoke(): JsonResponse
    {
        $activeSemester = Semester::query()->where('is_active', true)->first();
        $semesterId = $activeSemester?->id;

        $cacheKey = ApiCache::compositeKey(
            'vpaa.dashboard.insights',
            ['initial.data', 'rooms.index'],
            ['semester' => $semesterId],
        );

        $payload = Cache::remember(
            $cacheKey,
            ApiCache::LOOKUP_TTL_SECONDS,
            fn (): array => $this->build($semesterId),
        );

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(?int $semesterId): array
    {
        $meetings = $this->meetings($semesterId);
        $rooms = Rooms::query()->get(['id', 'room_code', 'building', 'room_type', 'status', 'department_id']);
        $physicalRooms = $rooms->reject(fn ($room) => $this->isVirtualRoom($room->room_type))->values();

        return [
            'semester_id' => $semesterId,
            'generated_at' => now()->toIso8601String(),
            'utilization' => $this->utilization($meetings, $physicalRooms),
            'peak_load' => $this->peakLoad($meetings),
            'coverage' => $this->coverage($meetings),
        ];
    }

    private function meetings(?int $semesterId): Collection
    {
        if ($semesterId === null) {
            return collect();
        }

        return Schedule::query()
            ->leftJoin('courses', 'schedules.course_id', '=', 'courses.id')
            ->leftJoin('rooms', 'schedules.room_id', '=', 'rooms.id')
            ->where('schedules.semester_id', $semesterId)
            ->whereIn('schedules.status', self::LIVE_STATUSES)
            ->select([
                'schedules.id',
                'schedules.section_id',
                'schedules.faculty_id',
                'schedules.room_id',
                'schedules.department_id',
                'schedules.day',
                'schedules.start_time',
                'schedules.end_time',
                'schedules.mode',
                'schedules.status',
                'courses.course_code',
                'rooms.room_type',
            ])
            ->get();
    }

    private function isVirtualRoom(?string $roomType): bool
    {
        return in_array(strtolower(trim((string) $roomType)), ['online', 'field'], true);
    }

    private function minutes(?string $time): int
    {
        $parts = explode(':', (string) $time);
        if (count($parts) < 2) {
            return 0;
        }

        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function utilization(Collection $meetings, Collection $physicalRooms): array
    {
        $openMinutes = max(
            0,
            $this->minutes(SchedulingPolicy::closingTime()) - $this->minutes(SchedulingPolicy::openingTime()),
        );
        $sundayDepartmentIds = Departments::query()
            ->where('sunday_classes_enabled', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $weeklyCapacityFor = static function ($room) use ($openMinutes, $sundayDepartmentIds): int {
            $sundayBookable = $room->department_id === null
                ? $sundayDepartmentIds !== []
                : in_array((int) $room->department_id, $sundayDepartmentIds, true);

            return $openMinutes * ($sundayBookable ? 7 : 6);
        };

        $bookedByRoom = [];
        $classesByRoom = [];
        foreach ($meetings as $row) {
            if ($row->room_id === null || $this->isVirtualRoom($row->room_type)) {
                continue;
            }
            $roomId = (int) $row->room_id;
            $duration = max(0, $this->minutes($row->end_time) - $this->minutes($row->start_time));
            $bookedByRoom[$roomId] = ($bookedByRoom[$roomId] ?? 0) + $duration;
            $classesByRoom[$roomId] = ($classesByRoom[$roomId] ?? 0) + 1;
        }

        $rows = $physicalRooms->map(function ($room) use ($bookedByRoom, $classesByRoom, $weeklyCapacityFor): array {
            $booked = $bookedByRoom[$room->id] ?? 0;
            $weeklyCapacity = $weeklyCapacityFor($room);

            return [
                'id' => (int) $room->id,
                'room_code' => $room->room_code,
                'building' => trim((string) ($room->building ?? '')) ?: null,
                'room_type' => $room->room_type,
                'meetings' => $classesByRoom[$room->id] ?? 0,
                'booked_minutes' => $booked,
                'capacity_minutes' => $weeklyCapacity,
                'utilization' => $weeklyCapacity > 0 ? (int) round(($booked / $weeklyCapacity) * 100) : 0,
                'is_unavailable' => trim(strtolower((string) ($room->status ?? 'available'))) !== 'available',
            ];
        })->values();

        $buildings = $rows
            ->groupBy(fn (array $row) => $row['building'] ?? 'Unassigned')
            ->map(function (Collection $group, string $building): array {
                $booked = (int) $group->sum('booked_minutes');
                $capacity = (int) $group->sum('capacity_minutes');

                return [
                    'building' => $building,
                    'rooms' => $group->count(),
                    'rooms_in_use' => $group->where('meetings', '>', 0)->count(),
                    'meetings' => (int) $group->sum('meetings'),
                    'booked_hours' => round($booked / 60, 1),
                    'utilization' => $capacity > 0 ? (int) round(($booked / $capacity) * 100) : 0,
                ];
            })
            ->sortByDesc('utilization')
            ->values()
            ->all();

        $busiest = $rows->sortByDesc('utilization')->take(8)->values()->all();
        $idle = $rows
            ->where('meetings', 0)
            ->where('is_unavailable', false)
            ->sortBy('room_code')
            ->values()
            ->all();

        return [
            'open_minutes_per_day' => $openMinutes,
            'rooms_total' => $rows->count(),
            'rooms_in_use' => $rows->where('meetings', '>', 0)->count(),
            'rooms_unavailable' => $rows->where('is_unavailable', true)->count(),
            'average_utilization' => $rows->isEmpty() ? 0 : (int) round($rows->avg('utilization')),
            'buildings' => $buildings,
            'busiest_rooms' => $busiest,
            'idle_rooms' => array_slice($idle, 0, 12),
            'idle_room_count' => count($idle),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function peakLoad(Collection $meetings): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $openHour = intdiv($this->minutes(SchedulingPolicy::openingTime()), 60);
        $closeHour = (int) ceil($this->minutes(SchedulingPolicy::closingTime()) / 60);
        $hours = range($openHour, max($openHour, $closeHour - 1));

        $matrix = [];
        foreach ($days as $day) {
            $matrix[$day] = array_fill(0, count($hours), 0);
        }

        foreach ($meetings as $row) {
            if (! isset($matrix[$row->day])) {
                continue;
            }
            $start = $this->minutes($row->start_time);
            $end = $this->minutes($row->end_time);
            foreach ($hours as $index => $hour) {
                $hourStart = $hour * 60;
                $hourEnd = $hourStart + 60;
                if ($start < $hourEnd && $end > $hourStart) {
                    $matrix[$row->day][$index]++;
                }
            }
        }

        $peak = 0;
        $peakDay = null;
        $peakHour = null;
        foreach ($matrix as $day => $counts) {
            foreach ($counts as $index => $count) {
                if ($count > $peak) {
                    $peak = $count;
                    $peakDay = $day;
                    $peakHour = $hours[$index];
                }
            }
        }

        return [
            'days' => $days,
            'hours' => array_values($hours),
            'matrix' => $matrix,
            'peak' => $peak,
            'peak_day' => $peakDay,
            'peak_hour' => $peakHour,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function coverage(Collection $meetings): array
    {
        $sectionsWithSchedule = $meetings->pluck('section_id')->filter()->unique();

        $unassignedClasses = $meetings
            ->filter(fn ($row) => $row->faculty_id === null)
            ->unique(fn ($row) => $row->section_id.':'.$row->course_code);

        $unassignedSections = $unassignedClasses->pluck('section_id')->filter()->unique();

        $roomlessClasses = $meetings
            ->filter(fn ($row) => $row->room_id === null)
            ->unique(fn ($row) => $row->section_id.':'.$row->course_code);

        return [
            'sections_with_schedule' => $sectionsWithSchedule->count(),
            'classes_without_instructor' => $unassignedClasses->count(),
            'sections_without_instructor' => $unassignedSections->count(),
            'classes_without_room' => $roomlessClasses->count(),
            'departments_with_gaps' => $unassignedClasses
                ->pluck('department_id')
                ->filter()
                ->unique()
                ->count(),
        ];
    }
}
