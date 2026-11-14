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
use App\Support\PersonName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Allowlisted archive types and their safe presentation. Display reads never select secrets
 * (passwords, tokens, Google identifiers) or image blobs (profile pictures, logos).
 */
final class ArchiveCatalog
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = [
        'users' => User::class,
        'departments' => Departments::class,
        'programs' => Program::class,
        'rooms' => Rooms::class,
        // Virtual: a building whose rooms are all archived, keyed by one of its archived rooms.
        // Listed after rooms so typeOf() still resolves a room record to 'rooms'.
        'buildings' => Rooms::class,
        'faculties' => Faculty::class,
        'designations' => Designation::class,
        'courses' => Course::class,
        'semesters' => Semester::class,
    ];

    public const TYPE_LABELS = [
        'users' => 'Account',
        'departments' => 'Department',
        'programs' => 'Program',
        'rooms' => 'Room',
        'buildings' => 'Building',
        'faculties' => 'Instructor',
        'designations' => 'Designation',
        'courses' => 'Course',
        'semesters' => 'Semester',
    ];

    public const DISPLAY_COLUMNS = [
        'users' => ['id', 'name', 'first_name', 'middle_initial', 'last_name', 'suffix', 'honorific_prefix', 'credentials', 'username', 'email', 'role', 'is_active', 'department_id', 'program_id', 'last_login_at', 'created_at', 'deleted_at'],
        'departments' => ['id', 'department_name', 'department_code', 'created_at', 'deleted_at'],
        'programs' => ['id', 'department_id', 'major', 'code', 'name', 'created_at', 'deleted_at'],
        'rooms' => ['id', 'room_code', 'building', 'room_type', 'allow_lecture_usage', 'status', 'department_id', 'home_program_id', 'created_at', 'deleted_at'],
        'buildings' => ['id', 'room_code', 'building', 'room_type', 'allow_lecture_usage', 'status', 'department_id', 'home_program_id', 'created_at', 'deleted_at'],
        'faculties' => ['id', 'user_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'honorific_prefix', 'credentials', 'employment_type', 'max_units', 'department_id', 'program_id', 'status', 'created_at', 'deleted_at'],
        'designations' => ['id', 'parent_id', 'name', 'code', 'deload_units', 'status', 'created_at', 'deleted_at'],
        'courses' => ['id', 'course_code', 'course_name', 'units', 'year_level', 'semester', 'department_id', 'teaching_department_id', 'teaching_program_id', 'teaching_source_program_id', 'program_id', 'status', 'created_at', 'deleted_at'],
        'semesters' => ['id', 'academic_year', 'semester', 'is_active', 'is_enabled', 'created_at', 'deleted_at'],
    ];

    /** @var array<string, array<int, mixed>> */
    private array $lookups = [];

    /** @var array<string, Collection<int, Model>>|null */
    private ?array $reference = null;

    /** @return class-string<Model>|null */
    public static function modelClass(string $type): ?string
    {
        return self::TYPES[$type] ?? null;
    }

    public static function typeOf(Model $record): string
    {
        return (string) array_search($record::class, self::TYPES, true);
    }

    public function find(string $type, int $id): ?Model
    {
        $modelClass = self::modelClass($type);

        return $modelClass === null ? null : $modelClass::withTrashed()->select(self::DISPLAY_COLUMNS[$type])->find($id);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Model>
     */
    public function findMany(string $type, array $ids): Collection
    {
        $modelClass = self::modelClass($type);
        if ($modelClass === null || $ids === []) {
            return collect();
        }

        return $modelClass::withTrashed()->select(self::DISPLAY_COLUMNS[$type])->whereKey($ids)->get();
    }

    public function label(string $type, Model $record): string
    {
        return match ($type) {
            'users' => (string) $record->getAttribute('name'),
            'departments' => (string) $record->getAttribute('department_name'),
            'programs' => trim((string) $record->getAttribute('code').' - '.(string) $record->getAttribute('name')),
            'rooms' => (string) $record->getAttribute('room_code'),
            'buildings' => (string) $record->getAttribute('building'),
            'faculties' => $this->facultyName($record),
            'designations' => (string) $record->getAttribute('name'),
            'courses' => trim((string) $record->getAttribute('course_code').' - '.(string) $record->getAttribute('course_name')),
            'semesters' => trim((string) $record->getAttribute('academic_year').' '.(string) $record->getAttribute('semester')),
            default => '#'.$record->getKey(),
        };
    }

    /** Short secondary identity that separates similar records in a list. */
    public function subtitle(string $type, Model $record): ?string
    {
        $parts = match ($type) {
            'users' => ['@'.$record->getAttribute('username'), AdministrativeRoleSlots::roleLabel((string) $record->getAttribute('role'))],
            'programs' => [$record->getAttribute('major') ? 'Major in '.$record->getAttribute('major') : null],
            'rooms' => [$record->getAttribute('building'), ucfirst((string) $record->getAttribute('room_type'))],
            'buildings' => [$this->roomCount($record).' · '.$this->roomCodes($record)],
            'faculties' => [$record->getAttribute('employment_type') ? ucwords(str_replace('_', ' ', (string) $record->getAttribute('employment_type'))) : null, $record->getAttribute('user_id') ? 'Linked account #'.$record->getAttribute('user_id') : null],
            'designations' => [$record->getAttribute('code'), $record->getAttribute('parent_id') ? 'Sub-designation' : null],
            'courses' => [$record->getAttribute('units') !== null ? $record->getAttribute('units').' units' : null],
            default => [],
        };

        $text = collect($parts)->filter(fn ($part) => $part !== null && $part !== '')->implode(' · ');

        return $text === '' ? null : $text;
    }

    /** @return array{department: ?array<string, mixed>, program: ?array<string, mixed>, term: ?array<string, mixed>} */
    public function context(string $type, Model $record): array
    {
        [$departmentId, $programId, $semesterId] = match ($type) {
            'departments' => [$record->getKey(), null, null],
            'programs' => [$record->getAttribute('department_id'), $record->getKey(), null],
            'rooms' => [$record->getAttribute('department_id'), $record->getAttribute('home_program_id'), null],
            'buildings' => [$this->sharedValue($record, 'department_id'), $this->sharedValue($record, 'home_program_id'), null],
            'designations' => [null, null, null],
            'semesters' => [null, null, $record->getKey()],
            default => [$record->getAttribute('department_id'), $record->getAttribute('program_id'), null],
        };
        $reference = $this->referenceData();
        $department = $departmentId === null ? null : $reference['departments']->get((int) $departmentId);
        $program = $programId === null ? null : $reference['programs']->get((int) $programId);
        $semester = $semesterId === null ? null : $reference['semesters']->get((int) $semesterId);

        return [
            'department' => $department === null ? null : [
                'id' => (int) $department->id,
                'code' => $department->department_code,
                'name' => $department->department_name,
                'archived' => $department->deleted_at !== null,
            ],
            'program' => $program === null ? null : [
                'id' => (int) $program->id,
                'code' => $program->code,
                'name' => $program->name,
                'archived' => $program->deleted_at !== null,
            ],
            'term' => $semester === null ? null : [
                'id' => (int) $semester->id,
                'label' => trim($semester->academic_year.' '.$semester->semester),
                'active' => (bool) $semester->is_active,
                'archived' => $semester->deleted_at !== null,
            ],
        ];
    }

    /**
     * Operational state kept through archive and restore. Restoring never changes it.
     *
     * @return array{label: string, tone: string}|null
     */
    public function status(string $type, Model $record): ?array
    {
        $active = fn (bool $isActive, string $on, string $off): array => ['label' => $isActive ? $on : $off, 'tone' => $isActive ? 'active' : 'inactive'];

        return match ($type) {
            'users' => $active((bool) $record->getAttribute('is_active'), 'Active account', 'Inactive account'),
            'faculties' => $active($record->getAttribute('status') === 'active', 'Active instructor', 'Inactive instructor'),
            'courses' => $active($record->getAttribute('status') === 'active', 'Active course', 'Inactive course'),
            'designations' => $active($record->getAttribute('status') === 'active', 'Active designation', 'Inactive designation'),
            'rooms' => $active($record->getAttribute('status') === 'available', 'Available room', 'Not available'),
            'buildings' => ['label' => $this->roomCount($record), 'tone' => 'neutral'],
            'semesters' => $record->getAttribute('is_active')
                ? ['label' => 'Active semester', 'tone' => 'active']
                : ['label' => $record->getAttribute('is_enabled') ? 'Not active' : 'Disabled semester', 'tone' => 'inactive'],
            default => null,
        };
    }

    /**
     * What restoring does, phrased so it reads correctly before and after the restore.
     *
     * @return list<string>
     */
    public function outcome(string $type, Model $record): array
    {
        return match ($type) {
            'users' => array_values(array_filter([
                $record->getAttribute('is_active')
                    ? 'The account returns to Users as active and can sign in with its existing credentials.'
                    : 'The account returns to Users but stays inactive. It cannot sign in until it is activated from Users.',
                in_array($record->getAttribute('role'), AdministrativeRoleSlots::ROLES, true) && $record->getAttribute('is_active')
                    ? 'It occupies the '.AdministrativeRoleSlots::slotLabel((string) $record->getAttribute('role')).' role for its scope again.'
                    : null,
                'A live linked instructor profile, if any, is resynced with the account role, name, and scope'
                    .($record->getAttribute('is_active') ? '.' : ' and marked inactive.'),
            ])),
            'faculties' => [$record->getAttribute('status') === 'active'
                ? 'The instructor returns to Instructors as active and can be assigned again.'
                : 'The instructor returns to Instructors but stays inactive and cannot be assigned until activated.'],
            'courses' => [$record->getAttribute('status') === 'active'
                ? 'The course returns to Courses as active.'
                : 'The course returns to Courses but stays inactive.'],
            'rooms' => [$record->getAttribute('status') === 'available'
                ? 'The room returns to Rooms and is available for scheduling.'
                : 'The room returns to Rooms but stays marked not available for scheduling.'],
            'buildings' => [
                'The building returns to Facilities with all '.$this->roomCount($record).' listed below.',
                'Each room keeps its own availability; rooms marked not available stay unavailable for scheduling.',
            ],
            'designations' => [$record->getAttribute('status') === 'active'
                ? 'The designation returns to the designation list as active.'
                : 'The designation returns to the designation list but stays inactive.'],
            'semesters' => ['The semester returns to the semester list. It is not activated.'],
            'departments' => ['The department returns to Departments. Archived programs, rooms, and accounts in it stay archived until restored separately.'],
            'programs' => ['The program returns to its department. Archived courses, rooms, and accounts in it stay archived until restored separately.'],
            default => [],
        };
    }

    /** @return list<array{label: string, value: string}> */
    public function identity(string $type, Model $record): array
    {
        $fields = match ($type) {
            'users' => [
                'Full name' => $record->getAttribute('name'),
                'Username' => $record->getAttribute('username'),
                'Email' => $record->getAttribute('email'),
                'Role' => AdministrativeRoleSlots::roleLabel((string) $record->getAttribute('role')),
                'Last sign-in' => $record->getAttribute('last_login_at')?->toIso8601String() ?? 'Never recorded',
            ],
            'departments' => ['Name' => $record->getAttribute('department_name'), 'Code' => $record->getAttribute('department_code')],
            'programs' => ['Code' => $record->getAttribute('code'), 'Name' => $record->getAttribute('name'), 'Major' => $record->getAttribute('major')],
            'rooms' => [
                'Room code' => $record->getAttribute('room_code'),
                'Building' => $record->getAttribute('building'),
                'Room type' => ucfirst((string) $record->getAttribute('room_type')),
                'Lecture use' => $record->getAttribute('allow_lecture_usage') ? 'Allowed' : 'Not allowed',
            ],
            'buildings' => [
                'Building' => $record->getAttribute('building'),
                'Rooms' => $this->roomCodes($record),
            ],
            'faculties' => [
                'Full name' => $this->facultyName($record),
                'Employment' => $record->getAttribute('employment_type') ? ucwords(str_replace('_', ' ', (string) $record->getAttribute('employment_type'))) : null,
                'Maximum units' => $record->getAttribute('max_units') !== null ? (string) $record->getAttribute('max_units') : null,
                'Linked account' => $record->getAttribute('user_id') ? '#'.$record->getAttribute('user_id') : 'None',
            ],
            'designations' => ['Name' => $record->getAttribute('name'), 'Code' => $record->getAttribute('code'), 'Deload units' => (string) $record->getAttribute('deload_units')],
            'courses' => [
                'Code' => $record->getAttribute('course_code'),
                'Title' => $record->getAttribute('course_name'),
                'Units' => $record->getAttribute('units') !== null ? (string) $record->getAttribute('units') : null,
                'Year level' => $record->getAttribute('year_level') !== null ? (string) $record->getAttribute('year_level') : null,
            ],
            'semesters' => ['Academic year' => $record->getAttribute('academic_year'), 'Term' => $record->getAttribute('semester')],
            default => [],
        };

        return collect($fields)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, string $label): array => ['label' => $label, 'value' => (string) $value])
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $blockers */
    public function summary(string $type, Model $record, array $blockers): array
    {
        return [
            'key' => $type.':'.$record->getKey(),
            'id' => (int) $record->getKey(),
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type],
            'label' => $this->label($type, $record),
            'subtitle' => $this->subtitle($type, $record),
            'context' => $this->context($type, $record),
            'status' => $this->status($type, $record),
            'deleted_at' => ($type === 'buildings'
                ? $this->archivedBuildingRooms($record)->max('deleted_at')
                : $record->getAttribute('deleted_at'))?->toIso8601String(),
            'restore' => [
                'state' => $blockers !== [] ? 'blocked' : 'clear',
                'blockers' => $blockers,
            ],
        ];
    }

    /** @return array{type: string, id: int, label: string} */
    public function reference(string $type, Model $record): array
    {
        return ['type' => $type, 'id' => (int) $record->getKey(), 'label' => $this->label($type, $record)];
    }

    /**
     * Departments, programs, and semesters including archived ones; small reference tables used
     * for context and filter options.
     *
     * @return array{departments: Collection<int, Departments>, programs: Collection<int, Program>, semesters: Collection<int, Semester>}
     */
    public function referenceData(): array
    {
        return $this->reference ??= [
            'departments' => Departments::withTrashed()->orderBy('department_code')->get(['id', 'department_code', 'department_name', 'deleted_at'])->keyBy('id'),
            'programs' => Program::withTrashed()->orderBy('code')->get(['id', 'department_id', 'code', 'name', 'major', 'deleted_at'])->keyBy('id'),
            'semesters' => Semester::withTrashed()->orderByDesc('academic_year')->orderBy('semester')->get(['id', 'academic_year', 'semester', 'is_active', 'deleted_at'])->keyBy('id'),
        ];
    }

    /**
     * Every room, live or archived, in the building of the given room.
     *
     * @return Collection<int, Rooms>
     */
    public function buildingRooms(Model $room): Collection
    {
        $building = $room->getAttribute('building');
        if ($building === null) {
            return collect([$room]);
        }

        return $this->lookups['building-rooms'][Rooms::buildingKey($building)]
            ??= Rooms::inBuilding($building, withTrashed: true)->sortBy('room_code', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** @return Collection<int, Rooms> */
    public function archivedBuildingRooms(Model $room): Collection
    {
        return $this->buildingRooms($room)->filter(fn (Rooms $row): bool => $row->trashed())->values();
    }

    /** A building is archived only while none of its rooms are live. */
    public function buildingArchived(Model $room): bool
    {
        return $room->getAttribute('building') !== null
            && $this->buildingRooms($room)->every(fn (Rooms $row): bool => $row->trashed());
    }

    private function roomCount(Model $room): string
    {
        $count = $this->archivedBuildingRooms($room)->count();

        return $count.' room'.($count === 1 ? '' : 's');
    }

    private function roomCodes(Model $room): string
    {
        return $this->archivedBuildingRooms($room)->pluck('room_code')->implode(', ');
    }

    /** The department or program every archived room shares, or null when they differ. */
    private function sharedValue(Model $room, string $column): mixed
    {
        $values = $this->archivedBuildingRooms($room)->pluck($column)->unique();

        return $values->count() === 1 ? $values->first() : null;
    }

    private function facultyName(Model $record): string
    {
        return PersonName::format(
            $record->getAttribute('honorific_prefix'),
            $record->getAttribute('first_name'),
            $record->getAttribute('middle_name'),
            $record->getAttribute('last_name'),
            $record->getAttribute('suffix'),
            $record->getAttribute('credentials'),
            fullMiddleName: true,
        );
    }
}
