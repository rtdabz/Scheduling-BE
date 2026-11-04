<?php

namespace App\Services\Archive;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\AdministrativeRoleSlots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Known restore preconditions, shared by the archive list, the detail view, and the restore
 * itself. Previews run unlocked and can go stale; the restore re-runs them under its locks.
 * Passing them does not prove a class schedule is valid: the rule engine and conflict scan
 * still decide that when the restore is submitted.
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
            'schedules' => $this->scheduleBlockers($record, $locked),
            'schedule-splits' => $this->splitBlockers($record, $locked),
            default => [],
        };

        return [...$blockers, ...$specific];
    }

    /** @return list<array<string, mixed>> */
    private function archivedParents(Model $record, bool $locked): array
    {
        $departmentColumns = $record instanceof Course ? ['department_id', 'teaching_department_id'] : ['department_id'];
        if ($record instanceof Departments || $record instanceof Designation || $record instanceof Semester || $record instanceof ScheduleSplit) {
            $departmentColumns = [];
        }
        $programColumns = match (true) {
            $record instanceof Program, $record instanceof Departments, $record instanceof Designation, $record instanceof Semester, $record instanceof ScheduleSplit => [],
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

        $label = AdministrativeRoleSlots::roleLabel($role);

        return [[
            'code' => 'role_occupied',
            'message' => "{$holder->name} (@{$holder->username}) is already the active {$label} for this scope. Deactivate or archive that account first.",
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
    private function scheduleBlockers(Model $schedule, bool $locked): array
    {
        $blockers = [];
        $semester = Semester::withTrashed()->find($schedule->getAttribute('semester_id'));
        if ($semester === null || $semester->trashed()) {
            $blockers[] = $semester === null
                ? ['code' => 'missing_parent', 'message' => 'The semester this class belonged to no longer exists.']
                : $this->parentBlocker('semesters', $semester, 'Restore the semester from Archives first.');
        } elseif (! $semester->is_active) {
            $blockers[] = [
                'code' => 'inactive_semester',
                'message' => 'This class belongs to a semester that is no longer active. Classes from past terms are kept in Schedule History and cannot be restored to the live timetable.',
            ];
        }
        if (! Sections::whereKey($schedule->getAttribute('section_id'))->exists()) {
            $blockers[] = ['code' => 'missing_parent', 'message' => 'The section this class belonged to was permanently removed, so the class cannot be restored.'];
        }
        foreach ([
            [Course::class, 'courses', 'course_id', 'Restore the course from Archives first.'],
            [Rooms::class, 'rooms', 'room_id', 'Restore the room from Archives first.'],
            [Faculty::class, 'faculties', 'faculty_id', 'Restore the instructor from Archives first.'],
        ] as [$modelClass, $type, $column, $message]) {
            $parent = $this->archived($modelClass, $schedule->getAttribute($column), $locked);
            if ($parent !== null) {
                $blockers[] = $this->parentBlocker($type, $parent, $message);
            }
        }

        return $blockers;
    }

    /** @return list<array<string, mixed>> */
    private function splitBlockers(Model $split, bool $locked): array
    {
        $schedule = Schedule::withTrashed()->find($split->getAttribute('schedule_id'));
        if ($schedule === null) {
            return [['code' => 'missing_parent', 'message' => 'The class this meeting belonged to was permanently removed.']];
        }
        if ($schedule->trashed()) {
            return [$this->parentBlocker('schedules', $schedule, 'Restore the class this meeting belongs to first. Restoring the class also restores its meeting splits.')];
        }

        $blockers = [...$this->archivedParents($schedule, $locked), ...$this->scheduleBlockers($schedule, $locked)];
        if (! in_array($schedule->status, Schedule::PLOTTING_EDITABLE_STATUSES, true)) {
            $blockers[] = ['code' => 'approval_stage', 'message' => 'This class is locked at its current approval stage. Recall it before restoring split metadata.'];
        }

        return [...$blockers, ...$this->liveDuplicate(
            ScheduleSplit::query()->where('schedule_id', $schedule->id),
            'This class already has live meeting split metadata.',
        )];
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
