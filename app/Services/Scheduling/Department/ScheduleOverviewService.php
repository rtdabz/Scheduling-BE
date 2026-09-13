<?php

namespace App\Services\Scheduling\Department;

use App\Models\Departments;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind the All Schedules overview.
 *
 * The screen used to derive its institution-wide numbers from the first N rows
 * of `/initial-data`, which is capped. Every count below is aggregated in SQL
 * over the whole term instead, so a department card never reports a subset of
 * its own schedule as the whole of it.
 *
 * Meetings are rolled up into the shapes the UI actually shows: a "class" is a
 * section/course pair, a "meeting" is one `schedules` row (an MWF class is
 * three), and a conflict count is the number of meetings involved in an
 * overlap rather than the number of overlapping pairs.
 */
class ScheduleOverviewService
{
    /** Rooms that stand in for "no physical room"; two meetings may share them. */
    private const VIRTUAL_ROOM_CODES = ['ONLINE', 'FIELD'];

    /** Delivery modes that need no room, so a null room is not a gap. */
    private const ROOMLESS_MODES = ['online', 'field'];

    public function __construct(
        private readonly DepartmentScheduleStatusDeriver $statuses,
    ) {}

    /**
     * @param  int|null  $departmentId  Limit to one department (a Dean's scope).
     * @return array<string, mixed>
     */
    public function overview(?int $departmentId = null): array
    {
        $term = Terms::query()->where('is_active', true)->first();
        $termId = $term?->id;

        $sections = $this->sections($termId, $departmentId);
        $sectionIds = $sections->pluck('id')->all();

        $meetingStats = $this->meetingStats($termId, $sectionIds);
        $dayLoads = $this->dayLoads($termId, $sectionIds);
        $statuses = $this->scheduleStatuses($termId, $sectionIds);
        $conflicts = $this->conflicts($termId, $sectionIds);

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
                // Departments needing attention first: the screen exists to
                // find them, not to rank by size.
                fn (array $row) => $row['conflicts']['total'] > 0 ? 0 : 1,
                fn (array $row) => $row['sections_total'] > 0 && $row['sections_scheduled'] < $row['sections_total'] ? 0 : 1,
                fn (array $row) => $row['code'],
            ])
            ->values();

        return [
            'term' => $term ? [
                'id' => $term->id,
                'academic_year' => $term->academic_year,
                'semester' => $term->semester,
            ] : null,
            'departments' => $departmentRows->all(),
            'totals' => $this->totals($departmentRows),
        ];
    }

    /** @return Collection<int, Sections> */
    private function sections(?int $termId, ?int $departmentId): Collection
    {
        return Sections::query()
            ->select('id', 'section_name', 'year_level', 'department_id', 'program_id')
            ->where('status', 'active')
            ->when($termId, fn ($query) => $query->where('term_id', $termId))
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
     * Per-section meeting totals. `classes` counts section/course pairs so the
     * card reports classes rather than the meeting rows they expand into.
     *
     * @param  list<int>  $sectionIds
     * @return Collection<int, object>
     */
    private function meetingStats(?int $termId, array $sectionIds): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        return $this->scheduleQuery($termId, $sectionIds)
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
     * Meetings per weekday, for the density strip on a section card.
     *
     * @param  list<int>  $sectionIds
     * @return Collection<int, array<string, int>>
     */
    private function dayLoads(?int $termId, array $sectionIds): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        return $this->scheduleQuery($termId, $sectionIds)
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
    private function scheduleStatuses(?int $termId, array $sectionIds): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        return $this->scheduleQuery($termId, $sectionIds)
            ->select('schedules.section_id', 'schedules.status')
            ->distinct()
            ->get()
            ->groupBy('section_id')
            ->map(fn (Collection $rows) => $rows->pluck('status')->all());
    }

    /**
     * Meetings involved in an overlap, counted per section and per kind.
     *
     * Overlap is evaluated across the whole term, not per department: a faculty
     * member or a room double-booked by two colleges is a conflict for both.
     * `total` counts each meeting once however many kinds it trips, which is
     * what the card's single "conflicts" figure means.
     *
     * @param  list<int>  $sectionIds
     * @return Collection<int, array<string, int>>
     */
    private function conflicts(?int $termId, array $sectionIds): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        $virtualRoomIds = DB::table('rooms')
            ->whereIn('room_code', self::VIRTUAL_ROOM_CODES)
            ->pluck('id')
            ->all();

        $flags = $this->scheduleQuery($termId, $sectionIds)
            ->select('schedules.id', 'schedules.section_id')
            ->selectSub(
                $this->overlapExists($termId)
                    ->whereColumn('other.faculty_id', 'schedules.faculty_id')
                    ->whereNotNull('schedules.faculty_id')
                    ->selectRaw('1'),
                'faculty_hit',
            )
            ->selectSub(
                $this->overlapExists($termId)
                    ->whereColumn('other.room_id', 'schedules.room_id')
                    ->whereNotNull('schedules.room_id')
                    ->whereNotIn('schedules.mode', self::ROOMLESS_MODES)
                    ->whereNotIn('other.mode', self::ROOMLESS_MODES)
                    ->when($virtualRoomIds !== [], fn ($query) => $query->whereNotIn('schedules.room_id', $virtualRoomIds))
                    ->selectRaw('1'),
                'room_hit',
            )
            ->selectSub(
                $this->overlapExists($termId)
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

    /**
     * A correlated sub-query matching any *other* meeting of the same term that
     * overlaps this one in time. Callers add the column that makes the overlap
     * a conflict (same faculty, same room, same section).
     *
     * The `(term, key, day, start, end)` indexes added for the solver cover
     * exactly this shape, so it stays an index lookup per row.
     */
    private function overlapExists(?int $termId): QueryBuilder
    {
        return DB::table('schedules AS other')
            ->whereColumn('other.id', '!=', 'schedules.id')
            ->whereColumn('other.day', 'schedules.day')
            ->whereColumn('other.start_time', '<', 'schedules.end_time')
            ->whereColumn('other.end_time', '>', 'schedules.start_time')
            ->when($termId, fn ($query) => $query->where('other.term_id', $termId))
            ->whereNull('other.deleted_at')
            ->limit(1);
    }

    /** @param  list<int>  $sectionIds */
    private function scheduleQuery(?int $termId, array $sectionIds): QueryBuilder
    {
        return DB::table('schedules')
            ->whereIn('schedules.section_id', $sectionIds)
            ->whereNull('schedules.deleted_at')
            ->when($termId, fn ($query) => $query->where('schedules.term_id', $termId));
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
            // A department is only as far along as its least advanced section,
            // the same rule the submission workflow applies.
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
