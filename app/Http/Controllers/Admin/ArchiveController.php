<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Models\User;
use App\Services\AdministrativeRoleSlots;
use App\Services\Archive\ArchiveCatalog;
use App\Services\Archive\ArchiveListQuery;
use App\Services\Archive\ArchiveProvenance;
use App\Services\Archive\ArchiveRestoreBlocked;
use App\Services\Archive\ArchiveRestoreChecks;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Schedule\ScheduleConflictScanner;
use App\Services\UserFacultyProfileService;
use App\Support\ApiCache;
use App\Support\DatabaseErrors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ArchiveController extends Controller
{
    private const CACHE_GROUPS = [
        'departments.index',
        'rooms.index',
        'faculty.index',
        'courses.index',
        'semesters.index',
        'semesters.active',
        'initial.data',
    ];

    public function __construct(
        private readonly UserFacultyProfileService $facultyProfiles,
        private readonly ScheduleConflictScanner $conflicts,
        private readonly ArchiveCatalog $catalog,
        private readonly ArchiveRestoreChecks $checks,
        private readonly ArchiveListQuery $listQuery,
        private readonly ArchiveProvenance $provenance,
        private readonly AdministrativeRoleSlots $roleSlots,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['all', ...array_keys(ArchiveCatalog::TYPES)])],
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'program_id' => ['nullable', 'integer', 'min:1'],
            'semester_id' => ['nullable', 'integer', 'min:1'],
            'archived_from' => ['nullable', 'date_format:Y-m-d'],
            'archived_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:archived_from'],
            'sort' => ['nullable', Rule::in(ArchiveListQuery::SORTS)],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $type = ($validated['type'] ?? 'all') === 'all' ? null : $validated['type'];
        $filters = array_filter([
            'search' => isset($validated['search']) ? trim($validated['search']) : null,
            'department_id' => $validated['department_id'] ?? null,
            'program_id' => $validated['program_id'] ?? null,
            'semester_id' => $validated['semester_id'] ?? null,
            'archived_from' => $validated['archived_from'] ?? null,
            'archived_to' => $validated['archived_to'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        // Type counts apply every filter except the type itself; total is the selected type's count.
        $counts = $this->listQuery->counts($filters);
        $total = $type === null ? array_sum($counts) : $counts[$type];
        $perPage = (int) ($validated['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($validated['page'] ?? 1), $lastPage);
        $rows = $this->listQuery->page(
            [...$filters, 'type' => $type],
            $validated['sort'] ?? 'archived_at',
            $validated['direction'] ?? 'desc',
            $page,
            $perPage,
        );

        return response()->json([
            'data' => $this->hydrate($rows),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($page * $perPage, $total),
            ],
            'counts' => $counts,
            'total_archived' => $filters === [] ? array_sum($counts) : array_sum($this->listQuery->counts([])),
            'options' => $this->filterOptions(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function show(string $type, int $id): JsonResponse
    {
        abort_if(ArchiveCatalog::modelClass($type) === null, 404, 'Archive type not found.');
        $record = $this->catalog->find($type, $id);
        abort_if($record === null, 404, 'This record no longer exists.');
        abort_if($type === 'buildings' && $record->getAttribute('building') === null, 404, 'This building no longer exists.');
        if (! $record->trashed() || ($type === 'buildings' && ! $this->catalog->buildingArchived($record))) {
            return $this->notArchived();
        }

        return response()->json(['data' => [
            ...$this->catalog->summary($type, $record, $this->checks->blockers($type, $record)),
            'identity' => $this->catalog->identity($type, $record),
            'outcome' => $this->catalog->outcome($type, $record),
            'provenance' => $this->provenance->for($type, $record),
            ...$this->impact($type, $record),
        ]]);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $modelClass = ArchiveCatalog::modelClass($type);
        abort_if($modelClass === null, 404, 'Archive type not found.');
        $record = $modelClass::withTrashed()->find($id);
        abort_if($record === null, 404, 'This record no longer exists.');
        abort_if($type === 'buildings' && $record->getAttribute('building') === null, 404, 'This building no longer exists.');
        if (! $record->trashed() || ($type === 'buildings' && ! $this->catalog->buildingArchived($record))) {
            return $this->alreadyRestored($type, $record);
        }

        try {
            $restored = match (true) {
                $type === 'buildings' => $this->restoreBuilding($request, $record),
                $record instanceof Schedule || $record instanceof ScheduleSplit => $this->restoreTimetable($request, $record),
                default => $this->restoreRecord($request, $type, $modelClass, $id),
            };
        } catch (ArchiveRestoreBlocked $exception) {
            return response()->json($exception->payload(), 422);
        } catch (ScheduleConflictException $exception) {
            return response()->json([...$exception->payload(), 'code' => 'schedule_violations'], 422);
        } catch (QueryException $exception) {
            return $this->databaseFailure($exception);
        }

        if ($restored === null) {
            return $this->alreadyRestored($type, $record->fresh() ?? $record);
        }

        ApiCache::forgetGroups($record instanceof Schedule || $record instanceof ScheduleSplit
            ? [...self::CACHE_GROUPS, 'instructor_assignments.index']
            : self::CACHE_GROUPS);

        $primary = $restored[0];
        $linkedCount = count($restored) - 1;

        return response()->json([
            'message' => trim(sprintf(
                '%s “%s” was restored%s. %s',
                ArchiveCatalog::TYPE_LABELS[$primary['type']],
                $primary['label'],
                $linkedCount > 0 ? " with {$linkedCount} linked record".($linkedCount === 1 ? '' : 's') : '',
                implode(' ', $primary['outcome']),
            )),
            'already_restored' => false,
            'restored' => $restored,
            'affected_count' => count($restored),
        ]);
    }

    /**
     * Record restore, linked profile sync, and the audit entry commit together or not at all.
     * The archived row is re-read under a lock and every precondition is re-checked first.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<array<string, mixed>>|null  null when another request restored it first
     */
    private function restoreRecord(Request $request, string $type, string $modelClass, int $id): ?array
    {
        return DB::transaction(function () use ($request, $type, $modelClass, $id): ?array {
            // An archived account's role and scope cannot change, so the unlocked read is safe
            // for choosing the scope lock, which must be taken before the account row lock.
            $scope = $modelClass === User::class ? User::withTrashed()->find($id, ['role', 'department_id', 'program_id']) : null;
            if ($scope !== null) {
                $this->roleSlots->lockScope((string) $scope->role, $scope->department_id, $scope->program_id);
            }
            $record = $modelClass::withTrashed()->whereKey($id)->lockForUpdate()->first();
            abort_if($record === null, 404, 'This record no longer exists.');
            if (! $record->trashed()) {
                return null;
            }

            $blockers = $this->checks->blockers($type, $record, locked: true);
            if ($blockers !== []) {
                throw new ArchiveRestoreBlocked($blockers);
            }

            $record->restore();
            if ($record instanceof User) {
                $this->facultyProfiles->sync($record);
            }
            $this->audit($request, $type, $record);

            return [$this->restoredEntry($type, $record)];
        });
    }

    /**
     * Restores every archived room of a fully archived building together, or none of them.
     * Rooms are re-read under row locks and every room's preconditions are re-checked first.
     *
     * @return list<array<string, mixed>>|null  null when a room of the building is already live
     */
    private function restoreBuilding(Request $request, Rooms $seed): ?array
    {
        return DB::transaction(function () use ($request, $seed): ?array {
            $ids = Rooms::inBuilding((string) $seed->building, withTrashed: true)->pluck('id')->all();
            $rooms = Rooms::withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($rooms->isEmpty() || $rooms->contains(fn (Rooms $room): bool => ! $room->trashed())) {
                return null;
            }

            $blockers = $this->checks->buildingBlockers($rooms, locked: true);
            if ($blockers !== []) {
                throw new ArchiveRestoreBlocked($blockers);
            }

            $building = [
                'type' => 'buildings',
                'id' => (int) $seed->getKey(),
                'label' => (string) $seed->building,
                'status' => ['label' => $rooms->count().' room'.($rooms->count() === 1 ? '' : 's'), 'tone' => 'neutral'],
                'outcome' => $this->catalog->outcome('buildings', $seed),
            ];
            $restored = [];
            foreach ($rooms->sortBy('room_code', SORT_NATURAL | SORT_FLAG_CASE) as $room) {
                $room->restore();
                $this->audit($request, 'rooms', $room, ['restored_with' => 'buildings:'.$seed->building]);
                $restored[] = $this->restoredEntry('rooms', $room);
            }

            return [$building, ...$restored];
        });
    }

    /**
     * Class restores keep the scheduling scope lock, rule engine, configured meeting-group check,
     * and conflict scan. Required meeting patterns restore their whole split group atomically.
     *
     * @return list<array<string, mixed>>|null
     */
    private function restoreTimetable(Request $request, Schedule|ScheduleSplit $record): ?array
    {
        $seed = $record instanceof Schedule ? $record : Schedule::find($record->schedule_id);
        if ($seed === null) {
            throw new ArchiveRestoreBlocked($this->checks->blockers('schedule-splits', $record));
        }
        $split = $record instanceof ScheduleSplit ? $record : ScheduleSplit::withTrashed()->where('schedule_id', $seed->id)->first();
        $ids = ArchiveCatalog::timetableGroupIds($seed, $split, $record instanceof Schedule);
        $semesterIds = Schedule::withTrashed()->whereIn('id', $ids)->pluck('semester_id')->unique()->map('intval')->all();

        return app(SchedulingScopeLock::class)->execute($semesterIds, fn (): ?array => DB::transaction(function () use ($request, $record, $ids): ?array {
            $rows = Schedule::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $current = $record::withTrashed()->whereKey($record->getKey())->lockForUpdate()->first();
            abort_if($current === null, 404, 'This record no longer exists.');
            if (! $current->trashed()) {
                return null;
            }

            $blockers = $record instanceof ScheduleSplit
                ? $this->checks->blockers('schedule-splits', $current, locked: true)
                : $rows->flatMap(fn (Schedule $row) => $this->checks->blockers('schedules', $row, locked: true))
                    ->unique(fn (array $blocker): string => $blocker['message'])->values()->all();
            if ($blockers !== []) {
                throw new ArchiveRestoreBlocked($blockers);
            }

            $restored = [];
            if ($record instanceof ScheduleSplit) {
                $current->restore();
                $this->audit($request, 'schedule-splits', $current);
                $restored[] = $this->restoredEntry('schedule-splits', $current);
            } else {
                $archivedSplitIds = ScheduleSplit::onlyTrashed()->whereIn('schedule_id', $ids)->pluck('id')->all();
                foreach ($rows->filter->trashed() as $row) {
                    $row->restore();
                    $this->audit($request, 'schedules', $row);
                    $restored[] = $this->restoredEntry('schedules', $row);
                }
                // Restoring a class restores its split metadata through the Schedule model event.
                foreach (ScheduleSplit::query()->whereKey($archivedSplitIds)->orderBy('id')->get() as $restoredSplit) {
                    $this->audit($request, 'schedule-splits', $restoredSplit, ['restored_with' => 'schedules:'.$restoredSplit->schedule_id]);
                    $restored[] = $this->restoredEntry('schedule-splits', $restoredSplit);
                }
                // The requested class leads the result; usort is stable for the rest.
                $isRequested = fn (array $entry): bool => $entry['type'] === 'schedules' && $entry['id'] === (int) $record->getKey();
                usort($restored, fn (array $a, array $b): int => $isRequested($b) <=> $isRequested($a));
            }

            $this->validateRestoredTimetable($ids);

            return $restored;
        }));
    }

    /** @param list<int> $ids */
    private function validateRestoredTimetable(array $ids): void
    {
        $rows = Schedule::whereIn('id', $ids)->get();
        $engine = app(RuleEngine::class);
        $violations = [];
        foreach ($rows as $row) {
            if ($row->split === null && ArchiveCatalog::requiresMeetingGroup($row)) {
                $violations[] = ['rule' => 'meeting_group_required', 'message' => 'This meeting pattern requires its linked split metadata before restoration.', 'schedule_id' => (int) $row->id];
            }
            $violations = [...$violations, ...$engine->validate([...$row->toArray(), 'ignore_schedule_id' => $row->id])];
        }
        $groups = $rows->pluck('split_group_id')->filter()->unique()->all();
        $partners = Schedule::whereHas('split', fn ($query) => $query->whereIn('split_group_id', $groups))->get();
        $violations = [...$violations, ...$engine->validateConfiguredMeetingGroups($partners->toArray())];
        if ($violations !== []) {
            throw new ScheduleConflictException($violations, 'This class cannot be restored under the current scheduling rules. Resolve the listed violations first.');
        }

        $cases = [];
        foreach ($rows->pluck('semester_id')->unique() as $semesterId) {
            $cases = [...$cases, ...$this->conflicts->scan((int) $semesterId, onlyScheduleIds: $ids)];
        }
        if ($cases !== []) {
            throw new ScheduleConflictException(
                array_map(fn ($case): array => [
                    'rule' => $case->rule,
                    'message' => $case->message(),
                    'schedule_id' => $case->scheduleId,
                    'other_schedule_id' => $case->otherScheduleId,
                    'day' => $case->day,
                    'overlap_start' => $case->overlapStart,
                    'overlap_end' => $case->overlapEnd,
                ], $cases),
                'This class cannot be restored because it would conflict with the current timetable. Move or remove the clashing classes first.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function impact(string $type, Model $record): array
    {
        if ($type === 'schedules') {
            $split = ScheduleSplit::withTrashed()->where('schedule_id', $record->getKey())->first();
            $ids = ArchiveCatalog::timetableGroupIds($record, $split, true);
            $linked = $this->catalog->findMany('schedules', $ids)
                ->reject(fn (Model $row): bool => (int) $row->getKey() === (int) $record->getKey())
                ->map(fn (Model $row): array => [
                    ...$this->catalog->reference('schedules', $row),
                    'archived' => $row->trashed(),
                    'relation' => $row->trashed() ? 'Restored together (required meeting pattern)' : 'Already live; validated together',
                    'restores' => $row->trashed(),
                ]);
            $splits = $this->catalog->findMany('schedule-splits', ScheduleSplit::onlyTrashed()->whereIn('schedule_id', $ids)->pluck('id')->map('intval')->all())
                ->map(fn (Model $row): array => [
                    ...$this->catalog->reference('schedule-splits', $row),
                    'archived' => true,
                    'relation' => 'Split metadata restored with its class',
                    'restores' => true,
                ]);

            return ['linked' => [...$linked->values()->all(), ...$splits->values()->all()]];
        }

        if ($type === 'schedule-splits') {
            $parent = $this->catalog->parentSchedule($record);

            return ['linked' => $parent === null ? [] : [[
                ...$this->catalog->reference('schedules', $parent),
                'archived' => $parent->trashed(),
                'relation' => 'Parent class (not restored by this action)',
                'restores' => false,
            ]]];
        }

        if ($type === 'buildings') {
            return ['linked' => $this->catalog->archivedBuildingRooms($record)->map(fn (Rooms $room): array => [
                ...$this->catalog->reference('rooms', $room),
                'archived' => true,
                'relation' => 'Room in this building (restored together)',
                'restores' => true,
            ])->all()];
        }

        if ($type === 'users') {
            $profile = Faculty::withTrashed()->where('user_id', $record->getKey())->first(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'deleted_at']);

            return ['linked' => $profile === null ? [] : [[
                ...$this->catalog->reference('faculties', $profile),
                'archived' => $profile->trashed(),
                'relation' => $profile->trashed() ? 'Linked instructor profile (stays archived)' : 'Linked instructor profile (resynced)',
                'restores' => false,
            ]]];
        }

        if (in_array($type, ['departments', 'programs'], true)) {
            $counts = array_filter($this->listQuery->counts([$type === 'departments' ? 'department_id' : 'program_id' => (int) $record->getKey()]));
            $counts[$type] = ($counts[$type] ?? 1) - 1;

            return ['linked' => [], 'archived_dependents' => array_filter($counts)];
        }

        return ['linked' => []];
    }

    /** @return array<string, mixed> */
    private function restoredEntry(string $type, Model $record): array
    {
        return [
            'type' => $type,
            'id' => (int) $record->getKey(),
            'label' => $this->catalog->label($type, $record),
            'status' => $this->catalog->status($type, $record),
            'outcome' => $this->catalog->outcome($type, $record),
        ];
    }

    private function alreadyRestored(string $type, Model $record): JsonResponse
    {
        return response()->json([
            'message' => sprintf('%s “%s” is no longer archived; it was already restored. No changes were made.', ArchiveCatalog::TYPE_LABELS[$type], $this->catalog->label($type, $record)),
            'already_restored' => true,
            'restored' => [],
            'affected_count' => 0,
        ]);
    }

    private function notArchived(): JsonResponse
    {
        return response()->json([
            'message' => 'This record is no longer archived. It may have been restored by another user.',
            'code' => 'not_archived',
        ], 404);
    }

    private function databaseFailure(QueryException $exception): JsonResponse
    {
        if (DatabaseErrors::isUniqueViolation($exception)) {
            return response()->json([
                'message' => 'This record cannot be restored because an active record now uses the same unique value. Nothing was restored.',
                'code' => 'unique_conflict',
            ], 422);
        }
        if (DatabaseErrors::isLockContention($exception)) {
            return response()->json([
                'message' => 'Another change to related records was in progress. Nothing was restored; try again.',
                'code' => 'busy',
            ], 409);
        }

        report($exception);

        return response()->json([
            'message' => 'The restore could not be completed because of a database error. Nothing was restored.',
            'code' => 'database_error',
        ], 500);
    }

    /** @param array<string, mixed> $extra */
    private function audit(Request $request, string $type, Model $record, array $extra = []): void
    {
        $scope = $record instanceof ScheduleSplit ? Schedule::withTrashed()->find($record->schedule_id) : $record;

        SchedulingAuditLog::create([
            'user_id' => $request->user()?->id,
            'semester_id' => $scope instanceof Schedule ? $scope->semester_id : null,
            'section_id' => $scope instanceof Schedule ? $scope->section_id : null,
            'department_id' => $record instanceof Departments ? $record->getKey() : $scope?->getAttribute('department_id'),
            'action' => 'record_restored',
            'metadata' => [
                'type' => $type,
                'id' => (int) $record->getKey(),
                'label' => $this->catalog->label($type, $record),
                ...$extra,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function hydrate(Collection $rows): array
    {
        $records = $rows->groupBy('archive_type')->map(fn (Collection $group, string $type) => $this->catalog
            ->findMany($type, $group->pluck('archive_id')->map('intval')->all())
            ->keyBy(fn (Model $record): int => (int) $record->getKey()));

        return $rows->map(function (object $row) use ($records): ?array {
            $record = $records[$row->archive_type][(int) $row->archive_id] ?? null;

            return $record === null ? null : $this->catalog->summary($row->archive_type, $record, $this->checks->blockers($row->archive_type, $record));
        })->filter()->values()->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function filterOptions(): array
    {
        $reference = $this->catalog->referenceData();

        return [
            'departments' => $reference['departments']->map(fn (Departments $department): array => [
                'id' => (int) $department->id,
                'code' => $department->department_code,
                'name' => $department->department_name,
                'archived' => $department->deleted_at !== null,
            ])->values()->all(),
            'programs' => $reference['programs']->map(fn (Program $program): array => [
                'id' => (int) $program->id,
                'department_id' => (int) $program->department_id,
                'code' => $program->code,
                'name' => $program->name,
                'archived' => $program->deleted_at !== null,
            ])->values()->all(),
            'semesters' => $reference['semesters']->map(fn ($semester): array => [
                'id' => (int) $semester->id,
                'label' => trim($semester->academic_year.' '.$semester->semester),
                'active' => (bool) $semester->is_active,
                'archived' => $semester->deleted_at !== null,
            ])->values()->all(),
        ];
    }
}
