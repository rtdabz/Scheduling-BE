<?php

namespace App\Services\Scheduling\Department;

use App\Models\Departments;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleOverviewService
{
    private const VIRTUAL_ROOM_CODES = ['ONLINE', 'FIELD'];

    private const ROOMLESS_MODES = ['online', 'field'];

    public function __construct(
        private readonly DepartmentScheduleStatusDeriver $statuses,
    ) {}

    /**
     * @param  int|null  $departmentId  Limit to one department (a Dean's scope).
     * @param  list<string>|null  $visibleStatuses  Count only meetings at these
     *                                              statuses (the VPAA's approved-only
     *                                              view); null counts every meeting.
     * @return array<string, mixed>
     */
    public function overview(?int $departmentId = null, ?array $visibleStatuses = null): array
    {
        $semester = Semester::query()->where('is_active', true)->first();
        $semesterId = $semester?->id;

        $sections = $this->sections($semesterId, $departmentId);
        $sectionIds = $sections->pluck('id')->all();

        $meetingStats = $this->meetingStats($semesterId, $sectionIds, $visibleStatuses);
        $dayLoads = $this->dayLoads($semesterId, $sectionIds, $visibleStatuses);
        $statuses = $this->scheduleStatuses($semesterId, $sectionIds, $visibleStatuses);
        $conflicts = $this->conflicts($semesterId, $sectionIds, $visibleStatuses);

        $departments = $this->departments($departmentId);

        $sectionRows = $sections->map(fn (Sections $section): array => $this->sectionRow(
            $section,
            $meetingStats->get($section->id),
            $dayLoads->get($section->id, []),
            $statuses->get($section->id, []),
            $conflicts->get($section->id, []),
        ));

        $byDepartment = $sectionRows->groupBy('department_id');

        $departmentRows = $departments
            ->map(fn (Departments $department): array => $this->departmentRow(
                $department,
                $byDepartment->get($department->id, collect()),
            ))
            ->sortBy([
                fn (array $row) => $row['conflicts']['total'] > 0 ? 0 : 1,
                fn (array $row) => $row['sections_total'] > 0 && $row['sections_scheduled'] < $row['sections_total'] ? 0 : 1,
                fn (array $row) => $row['code'],
            ])
            ->values();

        return [
            'semester' => $semester ? [
                'id' => $semester->id,
                'academic_year' => $semester->academic_year,
                'semester' => $semester->semester,
            ] : null,
            'departments' => $departmentRows->all(),
            'totals' => $this->totals($departmentRows),
        ];
    }

    /** @return Collection<int, Sections> */
    private function sections(?int $semesterId, ?int $departmentId): Collection
    {
        return Sections::query()
            ->select('id', 'section_name', 'year_level', 'department_id', 'program_id')
            ->where('status', 'active')
            ->when($semesterId, fn ($query) => $query->where('semester_id', $semesterId))
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('year_level')
            ->orderBy('section_name')
            ->get()
            ->keyBy('id');
    }

    /** @return Collection<int, Departments> */
    private function departments(?int $departmentId): Collection
    {
        return Departments::query()
            ->select('id', 'department_name', 'department_code')
            ->when($departmentId, fn ($query) => $query->whereKey($departmentId))
            ->orderBy('department_code')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<int>  $sectionIds
     * @return Collection<int, object>
     */
    private function meetingStats(?int $semesterId, array $sectionIds, ?array $visibleStatuses): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        return $this->scheduleQuery($semesterId, $sectionIds, $visibleStatuses)
            ->select('schedules.section_id')
            ->selectRaw('COUNT(*) AS meetings')
            ->selectRaw('COUNT(DISTINCT schedules.course_id) AS classes')
            ->selectRaw('SUM(CASE WHEN schedules.faculty_id IS NULL THEN 1 ELSE 0 END) AS unassigned_faculty')
            ->selectRaw(
                'SUM(CASE WHEN schedules.room_id IS NULL AND schedules.mode NOT IN (?, ?) THEN 1 ELSE 0 END) AS unassigned_rooms',
                self::ROOMLESS_MODES,
            )
            ->groupBy('schedules.section_id')
            ->get()
            ->keyBy('section_id');
    }

    /**
     * @param  list<int>  $sectionIds
     * @return Collection<int, array<string, int>>
     */
    private function dayLoads(?int $semesterId, array $sectionIds, ?array $visibleStatuses): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        return $this->scheduleQuery($semesterId, $sectionIds, $visibleStatuses)
            ->select('schedules.section_id', 'schedules.day')
            ->selectRaw('COUNT(*) AS meetings')
            ->groupBy('schedules.section_id', 'schedules.day')
            ->get()
            ->groupBy('section_id')
            ->map(fn (Collection $rows) => $rows->pluck('meetings', 'day')->map(fn ($count) => (int) $count)->all());
    }

    /**
     * @param  list<int>  $sectionIds
     * @return Collection<int, list<string>>
     */
    private function scheduleStatuses(?int $semesterId, array $sectionIds, ?array $visibleStatuses): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        return $this->scheduleQuery($semesterId, $sectionIds, $visibleStatuses)
            ->select('schedules.section_id', 'schedules.status')
            ->distinct()
            ->get()
            ->groupBy('section_id')
            ->map(fn (Collection $rows) => $rows->pluck('status')->all());
    }

    /**
     * @param  list<int>  $sectionIds
     * @return Collection<int, array<string, int>>
     */
    private function conflicts(?int $semesterId, array $sectionIds, ?array $visibleStatuses): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        $virtualRoomIds = DB::table('rooms')
            ->whereIn('room_code', self::VIRTUAL_ROOM_CODES)
            ->pluck('id')
            ->all();

        $flags = $this->scheduleQuery($semesterId, $sectionIds, $visibleStatuses)
            ->select('schedules.id', 'schedules.section_id')
            ->selectSub(
                $this->overlapExists($semesterId, $visibleStatuses)
                    ->whereColumn('other.faculty_id', 'schedules.faculty_id')
                    ->whereNotNull('schedules.faculty_id')
                    ->selectRaw('1'),
                'faculty_hit',
            )
            ->selectSub(
                $this->overlapExists($semesterId, $visibleStatuses)
                    ->whereColumn('other.room_id', 'schedules.room_id')
                    ->whereNotNull('schedules.room_id')
                    ->whereNotIn('schedules.mode', self::ROOMLESS_MODES)
                    ->whereNotIn('other.mode', self::ROOMLESS_MODES)
                    ->when($virtualRoomIds !== [], fn ($query) => $query->whereNotIn('schedules.room_id', $virtualRoomIds))
                    ->selectRaw('1'),
                'room_hit',
            )
            ->selectSub(
                $this->overlapExists($semesterId, $visibleStatuses)
                    ->whereColumn('other.section_id', 'schedules.section_id')
                    ->selectRaw('1'),
                'section_hit',
            );

        return DB::query()
            ->fromSub($flags, 'hits')
            ->select('section_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN faculty_hit IS NOT NULL THEN 1 ELSE 0 END), 0) AS faculty_conflicts')
            ->selectRaw('COALESCE(SUM(CASE WHEN room_hit IS NOT NULL THEN 1 ELSE 0 END), 0) AS room_conflicts')
            ->selectRaw('COALESCE(SUM(CASE WHEN section_hit IS NOT NULL THEN 1 ELSE 0 END), 0) AS section_conflicts')
            ->selectRaw('COALESCE(SUM(CASE WHEN faculty_hit IS NOT NULL OR room_hit IS NOT NULL OR section_hit IS NOT NULL THEN 1 ELSE 0 END), 0) AS total_conflicts')
            ->groupBy('section_id')
            ->get()
            ->keyBy('section_id')
            ->map(fn ($row): array => [
                'faculty' => (int) $row->faculty_conflicts,
                'room' => (int) $row->room_conflicts,
                'section' => (int) $row->section_conflicts,
                'total' => (int) $row->total_conflicts,
            ]);
    }

    private function overlapExists(?int $semesterId, ?array $visibleStatuses): QueryBuilder
    {
        return DB::table('schedules AS other')
            ->when($visibleStatuses !== null, fn ($query) => $query->whereIn('other.status', $visibleStatuses))
            ->whereColumn('other.id', '!=', 'schedules.id')
            ->whereColumn('other.day', 'schedules.day')
            ->whereColumn('other.start_time', '<', 'schedules.end_time')
            ->whereColumn('other.end_time', '>', 'schedules.start_time')
            ->when($semesterId, fn ($query) => $query->where('other.semester_id', $semesterId))
            ->whereNull('other.deleted_at')
            ->limit(1);
    }

    /**
     * @param  list<int>  $sectionIds
     * @param  list<string>|null  $visibleStatuses
     */
    private function scheduleQuery(?int $semesterId, array $sectionIds, ?array $visibleStatuses): QueryBuilder
    {
        return DB::table('schedules')
            ->whereIn('schedules.section_id', $sectionIds)
            ->whereNull('schedules.deleted_at')
            ->when($visibleStatuses !== null, fn ($query) => $query->whereIn('schedules.status', $visibleStatuses))
            ->when($semesterId, fn ($query) => $query->where('schedules.semester_id', $semesterId));
    }

    /**
     * @param  array<string, int>  $dayLoad
     * @param  list<string>  $scheduleStatuses
     * @param  array<string, int>  $conflicts
     * @return array<string, mixed>
     */
    private function sectionRow(
        Sections $section,
        ?object $stats,
        array $dayLoad,
        array $scheduleStatuses,
        array $conflicts,
    ): array {
        return [
            'id' => (int) $section->id,
            'code' => $section->section_name,
            'year_level' => (int) $section->year_level,
            'department_id' => (int) $section->department_id,
            'program_id' => $section->program_id !== null ? (int) $section->program_id : null,
            'status' => $this->statuses->derive($scheduleStatuses),
            'classes' => (int) ($stats->classes ?? 0),
            'meetings' => (int) ($stats->meetings ?? 0),
            'unassigned_faculty' => (int) ($stats->unassigned_faculty ?? 0),
            'unassigned_rooms' => (int) ($stats->unassigned_rooms ?? 0),
            'conflicts' => $conflicts + ['faculty' => 0, 'room' => 0, 'section' => 0, 'total' => 0],
            'day_load' => $dayLoad,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function departmentRow(Departments $department, Collection $sections): array
    {
        $scheduled = $sections->filter(fn (array $section): bool => $section['meetings'] > 0);

        return [
            'department_id' => (int) $department->id,
            'code' => $department->department_code,
            'name' => $department->department_name,
            'sections_total' => $sections->count(),
            'sections_scheduled' => $scheduled->count(),
            'classes' => (int) $sections->sum('classes'),
            'meetings' => (int) $sections->sum('meetings'),
            'unassigned_faculty' => (int) $sections->sum('unassigned_faculty'),
            'unassigned_rooms' => (int) $sections->sum('unassigned_rooms'),
            'conflicts' => [
                'faculty' => (int) $sections->sum(fn (array $section) => $section['conflicts']['faculty']),
                'room' => (int) $sections->sum(fn (array $section) => $section['conflicts']['room']),
                'section' => (int) $sections->sum(fn (array $section) => $section['conflicts']['section']),
                'total' => (int) $sections->sum(fn (array $section) => $section['conflicts']['total']),
            ],
            'status' => $this->statuses->derive(
                $sections->pluck('status')->all(),
            ),
            'sections' => $sections->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $departments
     * @return array<string, int>
     */
    private function totals(Collection $departments): array
    {
        return [
            'departments' => $departments->count(),
            'sections_total' => (int) $departments->sum('sections_total'),
            'sections_scheduled' => (int) $departments->sum('sections_scheduled'),
            'classes' => (int) $departments->sum('classes'),
            'meetings' => (int) $departments->sum('meetings'),
            'unassigned_faculty' => (int) $departments->sum('unassigned_faculty'),
            'unassigned_rooms' => (int) $departments->sum('unassigned_rooms'),
            'conflicts' => (int) $departments->sum(fn (array $row) => $row['conflicts']['total']),
        ];
    }
}
