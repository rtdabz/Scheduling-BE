<?php

namespace App\Services\Archive;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Semester;
use App\Models\User;
use App\Services\AdministrativeRoleSlots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Known restore preconditions, shared by the archive list, the detail view, and the restore
 * itself. Previews run unlocked and can go stale; the restore re-runs them under its locks.
 */
final class ArchiveRestoreChecks
{
    /** @var array<string, ?Model> */
    private array $memo = [];

    public function __construct(
        private readonly ArchiveCatalog $catalog,
        private readonly AdministrativeRoleSlots $roleSlots,
    ) {}

    /** @return list<array<string, mixed>> */
    public function blockers(string $type, Model $record, bool $locked = false): array
    {
        if ($type === 'buildings') {
            return $this->buildingBlockers($this->catalog->archivedBuildingRooms($record), $locked);
        }

        $blockers = [...$this->archivedParents($record, $locked)];

        $specific = match ($type) {
            'users' => $this->roleBlocker($record, $locked),
            'designations' => $this->designationBlockers($record, $locked),
            'programs' => $this->liveDuplicate(
                Program::query()->where('department_id', $record->getAttribute('department_id'))
                    ->where('code', $record->getAttribute('code'))
                    ->when($record->getAttribute('major') === null, fn ($query) => $query->whereNull('major'), fn ($query) => $query->where('major', $record->getAttribute('major'))),
                'An active program in this department already uses this code and major. Rename or archive it first.',
            ),
            'rooms' => $this->liveDuplicate(
                Rooms::query()->where('room_code', $record->getAttribute('room_code')),
                'An active room already uses this room code. Rename or archive it first.',
            ),
            'semesters' => $this->liveDuplicate(
                Semester::query()->where('academic_year', $record->getAttribute('academic_year'))->where('semester', $record->getAttribute('semester')),
                'An active semester already covers this academic year and term.',
            ),
            default => [],
        };

        return [...$blockers, ...$specific];
    }

    /**
     * A building restores all of its archived rooms together, so any room's blocker blocks it.
     * Room-specific problems name the room; a shared archived parent is listed once.
     *
     * @param  iterable<Rooms>  $rooms
     * @return list<array<string, mixed>>
     */
    public function buildingBlockers(iterable $rooms, bool $locked = false): array
    {
        $blockers = [];
        foreach ($rooms as $room) {
            foreach ($this->blockers('rooms', $room, $locked) as $blocker) {
                if ($blocker['code'] !== 'archived_parent') {
                    $blocker['message'] = "Room {$room->room_code}: {$blocker['message']}";
                }
                $blockers[$blocker['message']] = $blocker;
            }
        }

        return array_values($blockers);
    }

    /** @return list<array<string, mixed>> */
    private function archivedParents(Model $record, bool $locked): array
    {
        $departmentColumns = $record instanceof Course ? ['department_id', 'teaching_department_id'] : ['department_id'];
        if ($record instanceof Departments || $record instanceof Designation || $record instanceof Semester) {
            $departmentColumns = [];
        }
        $programColumns = match (true) {
            $record instanceof Program, $record instanceof Departments, $record instanceof Designation, $record instanceof Semester => [],
            $record instanceof Rooms => ['home_program_id'],
            $record instanceof Course => ['program_id', 'teaching_program_id', 'teaching_source_program_id'],
            default => ['program_id'],
        };

        $blockers = [];
        foreach ($departmentColumns as $column) {
            $parent = $this->archived(Departments::class, $record->getAttribute($column), $locked);
            if ($parent !== null) {
                $blockers[] = $this->parentBlocker('departments', $parent, "Restore the {$parent->department_name} department from Archives first.");
            }
        }
        foreach ($programColumns as $column) {
            $parent = $this->archived(Program::class, $record->getAttribute($column), $locked);
            if ($parent !== null) {
                $blockers[] = $this->parentBlocker('programs', $parent, "Restore the {$parent->code} program from Archives first.");
            }
        }

        return $blockers;
    }

    /** @return list<array<string, mixed>> */
    private function roleBlocker(Model $user, bool $locked): array
    {
        $role = (string) $user->getAttribute('role');
        // Existing rule: an archived holder stays archived while the slot is filled, even if inactive.
        if (! in_array($role, AdministrativeRoleSlots::ROLES, true)) {
            return [];
        }

        $departmentId = $user->getAttribute('department_id') === null ? null : (int) $user->getAttribute('department_id');
        $programId = $user->getAttribute('program_id') === null ? null : (int) $user->getAttribute('program_id');
        $holder = $locked
            ? $this->roleSlots->lockedHolder($role, $departmentId, $programId, (int) $user->getKey())
            : User::activeRoleHolder($role, $departmentId, $programId, (int) $user->getKey());
        if ($holder === null) {
            return [];
        }

        // Name the holder's actual role: a Director blocks a Secretary restore (and vice versa).
        $label = AdministrativeRoleSlots::roleLabel((string) $holder->role);
        $shared = in_array($role, User::DEPARTMENT_OFFICE_ROLES, true)
            ? ' A department has one Secretary or one Director, not both.'
            : '';

        return [[
            'code' => 'role_occupied',
            'message' => "{$holder->name} (@{$holder->username}) is already the active {$label} for this scope.{$shared} Deactivate or archive that account first.",
            'record' => ['type' => 'users', 'id' => (int) $holder->id, 'label' => $holder->name, 'archived' => false],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function designationBlockers(Model $designation, bool $locked): array
    {
        $parentId = $designation->getAttribute('parent_id');
        $parent = $this->archived(Designation::class, $parentId, $locked);
        if ($parent !== null) {
            return [$this->parentBlocker('designations', $parent, 'Restore its parent designation from Archives first.')];
        }

        return $this->liveDuplicate(
            Designation::query()->where('name', $designation->getAttribute('name'))
                ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId)),
            'A designation with this name already exists here. Rename it first.',
        );
    }

    /** @return list<array<string, mixed>> */
    private function liveDuplicate(Builder $query, string $message): array
    {
        return $query->exists() ? [['code' => 'unique_conflict', 'message' => $message]] : [];
    }

    /** @return array<string, mixed> */
    private function parentBlocker(string $type, Model $parent, string $message): array
    {
        return [
            'code' => 'archived_parent',
            'message' => $message,
            'record' => [...$this->catalog->reference($type, $parent), 'archived' => $parent->trashed()],
        ];
    }

    /** @param class-string<Model> $modelClass */
    private function archived(string $modelClass, mixed $id, bool $locked): ?Model
    {
        if ($id === null) {
            return null;
        }
        $key = $modelClass.':'.$id;
        if (! $locked && array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $query = $modelClass::onlyTrashed()->whereKey($id);
        if ($locked) {
            $query->sharedLock();
        }

        return $this->memo[$key] = $query->first();
    }
}
