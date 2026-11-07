<?php

namespace App\Services\Archive;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bounded archive listing. Each archived type contributes a minimal identity projection
 * (type, id, archived time) with filters and search applied in SQL; the database orders and
 * pages the combined rows, and only the requested page is hydrated afterwards.
 */
final class ArchiveListQuery
{
    /**
     * table, department column, program column, semester column, searchable columns, joins.
     * Joins add the parent class for meeting splits and searchable class labels.
     */
    private const SPECS = [
        'users' => ['users', 'r.department_id', 'r.program_id', null, ['r.name', 'r.username', 'r.email']],
        'departments' => ['departments', 'r.id', null, null, ['r.department_name', 'r.department_code']],
        'programs' => ['programs', 'r.department_id', 'r.id', null, ['r.code', 'r.name', 'r.major']],
        'rooms' => ['rooms', 'r.department_id', 'r.home_program_id', null, ['r.room_code', 'r.building']],
        'buildings' => ['rooms', 'r.department_id', 'r.home_program_id', null, ['r.building', 'r.room_code']],
        'faculties' => ['faculties', 'r.department_id', 'r.program_id', null, ['r.first_name', 'r.middle_name', 'r.last_name']],
        'designations' => ['designations', null, null, null, ['r.name', 'r.code']],
        'courses' => ['courses', 'r.department_id', 'r.program_id', null, ['r.course_code', 'r.course_name']],
        'semesters' => ['semesters', null, null, 'r.id', ['r.academic_year', 'r.semester']],
        'schedules' => ['schedules', 'r.department_id', 'r.program_id', 'r.semester_id', ['c.course_code', 'c.course_name', 's.section_name', 'rm.room_code']],
        'schedule-splits' => ['schedule_splits', 'p.department_id', 'p.program_id', 'p.semester_id', ['c.course_code', 'c.course_name', 's.section_name', 'rm.room_code']],
    ];

    public const SORTS = ['archived_at', 'type'];

    /**
     * Counts per type for every filter except the type filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function counts(array $filters): array
    {
        $found = DB::query()
            ->fromSub($this->union(array_keys(self::SPECS), $filters), 'archive_rows')
            ->select('archive_type')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('archive_type')
            ->pluck('aggregate', 'archive_type');

        return collect(self::SPECS)->map(fn ($spec, string $type): int => (int) ($found[$type] ?? 0))->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object{archive_type: string, archive_id: int|string, archived_at: string}>
     */
    public function page(array $filters, string $sort, string $direction, int $page, int $perPage): Collection
    {
        $type = $filters['type'] ?? null;
        $types = $type === null ? array_keys(self::SPECS) : [$type];
        $query = DB::query()->fromSub($this->union($types, $filters), 'archive_rows');

        if ($sort === 'type') {
            $query->orderBy('archive_type', $direction)->orderByDesc('archived_at');
        } else {
            $query->orderBy('archived_at', $direction)->orderBy('archive_type');
        }

        return $query->orderBy('archive_id', $direction)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();
    }

    /**
     * @param  list<string>  $types
     * @param  array<string, mixed>  $filters
     */
    private function union(array $types, array $filters): Builder
    {
        $queries = array_map(fn (string $type): Builder => $this->projection($type, $filters), $types);
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return $union;
    }

    /** @param array<string, mixed> $filters */
    private function projection(string $type, array $filters): Builder
    {
        [$table, $department, $program, $semester, $searchable] = self::SPECS[$type];
        $query = DB::table("{$table} as r")->whereNotNull('r.deleted_at');

        // A building is archived when none of its rooms are live. It is listed once, represented by
        // its lowest archived room id, and its rooms are listed inside it instead of on their own.
        $liveSibling = fn (Builder $live) => $live->from('rooms as live')
            ->whereNull('live.deleted_at')
            ->whereRaw('LOWER(TRIM(live.building)) = LOWER(TRIM(r.building))');
        if ($type === 'buildings') {
            $query->selectRaw("'buildings' AS archive_type, MIN(r.id) AS archive_id, MAX(r.deleted_at) AS archived_at")
                ->whereNotNull('r.building')
                ->whereNotExists($liveSibling)
                ->groupByRaw('LOWER(TRIM(r.building))');
        } else {
            $query->selectRaw("'{$type}' AS archive_type, r.id AS archive_id, r.deleted_at AS archived_at");
        }
        if ($type === 'rooms') {
            $query->where(fn (Builder $rooms) => $rooms->whereNull('r.building')->orWhereExists($liveSibling));
        }

        if ($type === 'schedule-splits') {
            $query->leftJoin('schedules as p', 'p.id', '=', 'r.schedule_id');
        }
        if (in_array($type, ['schedules', 'schedule-splits'], true)) {
            $owner = $type === 'schedules' ? 'r' : 'p';
            $query->leftJoin('courses as c', 'c.id', '=', "{$owner}.course_id")
                ->leftJoin('sections as s', 's.id', '=', "{$owner}.section_id")
                ->leftJoin('rooms as rm', 'rm.id', '=', "{$owner}.room_id");
        }

        foreach (['department_id' => $department, 'program_id' => $program, 'semester_id' => $semester] as $filter => $column) {
            if (! isset($filters[$filter])) {
                continue;
            }
            $column === null ? $query->whereRaw('1 = 0') : $query->where($column, (int) $filters[$filter]);
        }

        if (isset($filters['archived_from'])) {
            $query->where('r.deleted_at', '>=', Carbon::parse($filters['archived_from'])->startOfDay());
        }
        if (isset($filters['archived_to'])) {
            $query->where('r.deleted_at', '<=', Carbon::parse($filters['archived_to'])->endOfDay());
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($searchable, $pattern): void {
                foreach ($searchable as $column) {
                    $query->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query;
    }
}
