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
use App\Services\Scheduling\Support\SchedulingPolicy;
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
        'faculties' => Faculty::class,
        'designations' => Designation::class,
        'courses' => Course::class,
        'semesters' => Semester::class,
        'schedules' => Schedule::class,
        'schedule-splits' => ScheduleSplit::class,
    ];

    public const TYPE_LABELS = [
        'users' => 'Account',
        'departments' => 'Department',
        'programs' => 'Program',
        'rooms' => 'Room',
        'faculties' => 'Instructor',
        'designations' => 'Designation',
        'courses' => 'Course',
        'semesters' => 'Semester',
        'schedules' => 'Class schedule',
        'schedule-splits' => 'Meeting split',
    ];

    public const DISPLAY_COLUMNS = [
        'users' => ['id', 'name', 'first_name', 'middle_initial', 'last_name', 'suffix', 'username', 'email', 'role', 'is_active', 'department_id', 'program_id', 'last_login_at', 'created_at', 'deleted_at'],
        'departments' => ['id', 'department_name', 'department_code', 'created_at', 'deleted_at'],
        'programs' => ['id', 'department_id', 'major', 'code', 'name', 'created_at', 'deleted_at'],
        'rooms' => ['id', 'room_code', 'building', 'room_type', 'allow_lecture_usage', 'status', 'department_id', 'home_program_id', 'created_at', 'deleted_at'],
        'faculties' => ['id', 'user_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employment_type', 'max_units', 'department_id', 'program_id', 'status', 'created_at', 'deleted_at'],
        'designations' => ['id', 'parent_id', 'name', 'code', 'deload_units', 'status', 'created_at', 'deleted_at'],
        'courses' => ['id', 'course_code', 'course_name', 'units', 'year_level', 'semester', 'department_id', 'teaching_department_id', 'teaching_program_id', 'teaching_source_program_id', 'program_id', 'status', 'created_at', 'deleted_at'],
        'semesters' => ['id', 'academic_year', 'semester', 'is_active', 'is_enabled', 'created_at', 'deleted_at'],
        'schedules' => ['id', 'semester_id', 'section_id', 'course_id', 'faculty_id', 'room_id', 'department_id', 'program_id', 'day', 'start_time', 'end_time', 'mode', 'is_hybrid', 'preferred_pattern', 'status', 'created_at', 'deleted_at'],
        'schedule-splits' => ['id', 'schedule_id', 'split_group_id', 'meeting_type', 'meeting_index', 'created_at', 'deleted_at'],
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

        $records = $modelClass::withTrashed()->select(self::DISPLAY_COLUMNS[$type])->whereKey($ids)->get();
        $this->preload($type, $records);

        return $records;
    }

    /**
     * Linked rows a timetable restore acts on together: required meeting patterns restore every
     * schedule in their split group; a single meeting split restores only itself.
     *
     * @return list<int>
     */
    public static function timetableGroupIds(Schedule $seed, ?ScheduleSplit $split, bool $restoringSchedule): array
    {
        $ids = [(int) $seed->id];
        if ($restoringSchedule && $split !== null && self::requiresMeetingGroup($seed)) {
            $ids = ScheduleSplit::withTrashed()->where('split_group_id', $split->split_group_id)
                ->pluck('schedule_id')->map('intval')->unique()->sort()->values()->all();
        }

        return $ids;
    }

    public static function requiresMeetingGroup(Schedule $schedule): bool
    {
        return (bool) $schedule->is_hybrid
            || SchedulingPolicy::isFixedMeetingPattern($schedule->preferred_pattern)
            || SchedulingPolicy::consecutiveDayCount($schedule->preferred_pattern) !== null;
    }

    /** @param Collection<int, Model> $records */
    public function preload(string $type, Collection $records): void
    {
        if (! in_array($type, ['schedules', 'schedule-splits'], true) || $records->isEmpty()) {
            return;
        }

        if ($type === 'schedule-splits') {
            $missing = $records->pluck('schedule_id')->map('intval')->reject(fn (int $id): bool => array_key_exists($id, $this->lookups['schedules'] ?? []));
            foreach (Schedule::withTrashed()->select(self::DISPLAY_COLUMNS['schedules'])->whereKey($missing->all())->get() as $schedule) {
                $this->lookups['schedules'][(int) $schedule->id] = $schedule;
            }
            $schedules = collect($records->map(fn (Model $split) => $this->lookups['schedules'][(int) $split->schedule_id] ?? null)->filter()->all());
        } else {
            $schedules = $records;
            foreach ($records as $schedule) {
                $this->lookups['schedules'][(int) $schedule->id] = $schedule;
            }
        }

        $this->fill('courses', $schedules->pluck('course_id'), fn (array $ids) => Course::withTrashed()->whereKey($ids)->pluck('course_code', 'id'));
        $this->fill('sections', $schedules->pluck('section_id'), fn (array $ids) => Sections::query()->whereKey($ids)->pluck('section_name', 'id'));
        $this->fill('rooms', $schedules->pluck('room_id'), fn (array $ids) => Rooms::withTrashed()->whereKey($ids)->pluck('room_code', 'id'));
    }

    public function label(string $type, Model $record): string
    {
        if (in_array($type, ['schedules', 'schedule-splits'], true)) {
            $this->preload($type, collect([$record]));
        }

        return match ($type) {
            'users' => (string) $record->getAttribute('name'),
            'departments' => (string) $record->getAttribute('department_name'),
            'programs' => trim((string) $record->getAttribute('code').' - '.(string) $record->getAttribute('name')),
            'rooms' => (string) $record->getAttribute('room_code'),
            'faculties' => $this->facultyName($record),
            'designations' => (string) $record->getAttribute('name'),
            'courses' => trim((string) $record->getAttribute('course_code').' - '.(string) $record->getAttribute('course_name')),
            'semesters' => trim((string) $record->getAttribute('academic_year').' '.(string) $record->getAttribute('semester')),
            'schedules' => $this->scheduleText($record),
            'schedule-splits' => 'Meeting of '.$this->scheduleText($this->parentSchedule($record)),
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
            'faculties' => [$record->getAttribute('employment_type') ? ucwords(str_replace('_', ' ', (string) $record->getAttribute('employment_type'))) : null, $record->getAttribute('user_id') ? 'Linked account #'.$record->getAttribute('user_id') : null],
            'designations' => [$record->getAttribute('code'), $record->getAttribute('parent_id') ? 'Sub-designation' : null],
            'courses' => [$record->getAttribute('units') !== null ? $record->getAttribute('units').' units' : null],
            'schedules', 'schedule-splits' => [$this->meetingText($type, $record)],
            default => [],
        };

        $text = collect($parts)->filter(fn ($part) => $part !== null && $part !== '')->implode(' · ');

        return $text === '' ? null : $text;
    }

    /** @return array{department: ?array<string, mixed>, program: ?array<string, mixed>, term: ?array<string, mixed>} */
    public function context(string $type, Model $record): array
    {
        $scope = $type === 'schedule-splits' ? $this->parentSchedule($record) : $record;
        [$departmentId, $programId, $semesterId] = match ($type) {
            'departments' => [$record->getKey(), null, null],
            'programs' => [$record->getAttribute('department_id'), $record->getKey(), null],
            'rooms' => [$record->getAttribute('department_id'), $record->getAttribute('home_program_id'), null],
            'designations' => [null, null, null],
            'semesters' => [null, null, $record->getKey()],
            'schedules', 'schedule-splits' => [$scope?->getAttribute('department_id'), $scope?->getAttribute('program_id'), $scope?->getAttribute('semester_id')],
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
        $schedule = $type === 'schedule-splits' ? $this->parentSchedule($record) : $record;

        return match ($type) {
            'users' => $active((bool) $record->getAttribute('is_active'), 'Active account', 'Inactive account'),
            'faculties' => $active($record->getAttribute('status') === 'active', 'Active instructor', 'Inactive instructor'),
            'courses' => $active($record->getAttribute('status') === 'active', 'Active course', 'Inactive course'),
            'designations' => $active($record->getAttribute('status') === 'active', 'Active designation', 'Inactive designation'),
            'rooms' => $active($record->getAttribute('status') === 'available', 'Available room', 'Not available'),
            'semesters' => $record->getAttribute('is_active')
                ? ['label' => 'Active semester', 'tone' => 'active']
                : ['label' => $record->getAttribute('is_enabled') ? 'Not active' : 'Disabled semester', 'tone' => 'inactive'],
            'schedules', 'schedule-splits' => $schedule === null ? null : ['label' => 'Stage: '.ucwords(str_replace('_', ' ', (string) $schedule->getAttribute('status'))), 'tone' => 'neutral'],
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
                    ? 'It occupies the '.AdministrativeRoleSlots::roleLabel((string) $record->getAttribute('role')).' role for its scope again.'
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
            'designations' => [$record->getAttribute('status') === 'active'
                ? 'The designation returns to the designation list as active.'
                : 'The designation returns to the designation list but stays inactive.'],
            'semesters' => ['The semester returns to the semester list. It is not activated.'],
            'departments' => ['The department returns to Departments. Archived programs, rooms, and accounts in it stay archived until restored separately.'],
            'programs' => ['The program returns to its department. Archived courses, rooms, and accounts in it stay archived until restored separately.'],
            'schedules' => ['The class returns to the live timetable at its archived approval stage after current scheduling rules and conflicts are checked.'],
            'schedule-splits' => ['The meeting split metadata returns to its live class. The class must be at an editable approval stage.'],
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
            'schedules' => $this->scheduleIdentity($record),
            'schedule-splits' => [
                ...$this->scheduleIdentity($this->parentSchedule($record)),
                'Meeting' => ucfirst((string) $record->getAttribute('meeting_type')).' #'.((int) $record->getAttribute('meeting_index') + 1),
            ],
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
            'deleted_at' => $record->getAttribute('deleted_at')?->toIso8601String(),
            'restore' => [
                'state' => $blockers !== [] ? 'blocked' : (in_array($type, ['schedules', 'schedule-splits'], true) ? 'validation' : 'clear'),
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

    public function parentSchedule(Model $split): ?Schedule
    {
        $id = (int) $split->getAttribute('schedule_id');
        if (! array_key_exists($id, $this->lookups['schedules'] ?? [])) {
            $this->preload('schedule-splits', collect([$split]));
        }

        return $this->lookups['schedules'][$id] ?? null;
    }

    private function facultyName(Model $record): string
    {
        return trim(collect([
            $record->getAttribute('first_name'),
            $record->getAttribute('middle_name'),
            $record->getAttribute('last_name'),
            $record->getAttribute('suffix'),
        ])->filter()->implode(' '));
    }

    private function scheduleText(?Model $schedule): string
    {
        if ($schedule === null) {
            return 'Unknown class';
        }

        return sprintf(
            '%s · %s · %s · %s',
            $this->lookups['courses'][$schedule->course_id] ?? 'Course #'.$schedule->course_id,
            $this->lookups['sections'][$schedule->section_id] ?? 'Section removed',
            $this->when($schedule),
            $this->lookups['rooms'][$schedule->room_id] ?? 'No room',
        );
    }

    private function when(Model $schedule): string
    {
        return ucfirst((string) $schedule->day).' '.substr((string) $schedule->start_time, 0, 5).'-'.substr((string) $schedule->end_time, 0, 5);
    }

    private function meetingText(string $type, Model $record): ?string
    {
        if ($type === 'schedule-splits') {
            return ucfirst((string) $record->getAttribute('meeting_type')).' meeting';
        }

        return $record->getAttribute('is_hybrid') ? 'Hybrid meeting pattern' : ($record->getAttribute('mode') ? ucfirst((string) $record->getAttribute('mode')) : null);
    }

    /** @return array<string, ?string> */
    private function scheduleIdentity(?Model $schedule): array
    {
        if ($schedule === null) {
            return ['Class' => 'Unknown class'];
        }

        return [
            'Course' => $this->lookups['courses'][$schedule->course_id] ?? 'Course #'.$schedule->course_id,
            'Section' => $this->lookups['sections'][$schedule->section_id] ?? 'Section removed',
            'Meeting time' => $this->when($schedule),
            'Room' => $this->lookups['rooms'][$schedule->room_id] ?? 'No room',
            'Mode' => $schedule->mode ? ucfirst((string) $schedule->mode) : null,
            'Meeting pattern' => $schedule->preferred_pattern ? (string) $schedule->preferred_pattern : null,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @param  callable(list<int>): Collection<int|string, mixed>  $load
     */
    private function fill(string $lookup, Collection $ids, callable $load): void
    {
        $missing = $ids->filter()->map('intval')->unique()
            ->reject(fn (int $id): bool => array_key_exists($id, $this->lookups[$lookup] ?? []))
            ->values()->all();
        if ($missing === []) {
            return;
        }

        $found = $load($missing);
        foreach ($missing as $id) {
            $this->lookups[$lookup][$id] = $found[$id] ?? null;
        }
    }
}
