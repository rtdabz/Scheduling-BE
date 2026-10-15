<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Schedule\ScheduleConflictScanner;
use App\Services\UserFacultyProfileService;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArchiveController extends Controller
{
    public function __construct(
        private readonly UserFacultyProfileService $facultyProfiles,
        private readonly ScheduleConflictScanner $conflicts,
    ) {}

    /** @var array<string, class-string<Model>> */
    private const TYPES = [
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

    public function index(): JsonResponse
    {
        $departments = Departments::withTrashed()->pluck('department_code', 'id');
        $counts = [];

        $records = collect(self::TYPES)->flatMap(function (string $modelClass, string $type) use ($departments, &$counts) {
            $rows = $modelClass::onlyTrashed()->latest('deleted_at')->get();
            $counts[$type] = $rows->count();
            $schedules = $this->scheduleLookups($type, $rows);

            return $rows->map(fn (Model $record): array => [
                'id' => $record->getKey(),
                'type' => $type,
                'label' => $this->label($type, $record, $schedules),
                'context' => $this->context($type, $record, $departments, $schedules),
                'deleted_at' => $record->getAttribute('deleted_at'),
            ]);
        })->sortByDesc('deleted_at')->values();

        return response()->json(['data' => $records, 'counts' => $counts]);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $modelClass = self::TYPES[$type] ?? null;
        abort_if($modelClass === null, 404, 'Archive type not found.');

        $record = $modelClass::onlyTrashed()->findOrFail($id);

        if (($blocked = $this->archivedParent($record)) !== null) {
            return response()->json([
                'message' => "Restore the {$blocked} from Archives first.",
            ], 422);
        }

        if ($record instanceof Schedule || $record instanceof ScheduleSplit) {
            return $this->restoreTimetable($request, $record);
        }

        if ($record instanceof Designation && ($message = $this->designationRestoreBlock($record)) !== null) {
            return response()->json(['message' => $message], 422);
        }

        if ($record instanceof User) {
            $holder = User::activeRoleHolder(
                (string) $record->role,
                $record->department_id === null ? null : (int) $record->department_id,
                $record->program_id === null ? null : (int) $record->program_id,
                (int) $record->id,
            );
            if ($holder !== null) {
                $role = str_replace('_', ' ', ucfirst((string) $record->role));

                return response()->json([
                    'message' => "This account cannot be restored: {$holder->name} is already the active {$role}. Archive or deactivate them first.",
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($record): void {
                $record->restore();
                if ($record instanceof User) {
                    $this->facultyProfiles->sync($record);
                }
            });
        } catch (QueryException) {
            return response()->json([
                'message' => 'This record cannot be restored because an active record now uses the same unique value.',
            ], 422);
        }

        $this->audit($request, $type, $record);

        ApiCache::forgetGroups([
            'departments.index',
            'rooms.index',
            'faculty.index',
            'courses.index',
            'semesters.index',
            'semesters.active',
            'initial.data',
        ]);

        return response()->json(['message' => 'Record restored successfully.']);
    }

    private function restoreTimetable(Request $request, Schedule|ScheduleSplit $record): JsonResponse
    {
        $seed = $record instanceof Schedule ? $record : Schedule::find($record->schedule_id);
        if ($seed === null) {
            return response()->json(['message' => 'Restore the schedule this meeting belongs to first.'], 422);
        }
        $split = $record instanceof ScheduleSplit ? $record : ScheduleSplit::withTrashed()->where('schedule_id', $seed->id)->first();
        $ids = [(int) $seed->id];
        $requiredGroup = $seed->is_hybrid
            || \App\Services\Scheduling\Support\SchedulingPolicy::isFixedMeetingPattern($seed->preferred_pattern)
            || \App\Services\Scheduling\Support\SchedulingPolicy::consecutiveDayCount($seed->preferred_pattern) !== null;
        if ($record instanceof Schedule && $requiredGroup && $split !== null) {
            $ids = ScheduleSplit::withTrashed()->where('split_group_id', $split->split_group_id)->pluck('schedule_id')->map('intval')->all();
        }
        $semesterIds = Schedule::withTrashed()->whereIn('id', $ids)->pluck('semester_id')->unique()->map('intval')->all();
        try {
            app(\App\Services\Scheduling\Lock\SchedulingScopeLock::class)->execute($semesterIds, function () use ($request, $record, $ids): void {
                DB::transaction(function () use ($request, $record, $ids): void {
                    $rows = Schedule::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                    foreach ($rows as $row) {
                        $message = $this->archivedParent($row);
                        if ($message !== null) {
                            abort(422, 'Restore the '.$message.' from Archives first.');
                        }
                        if (($message = $this->scheduleRestoreBlock($row)) !== null) {
                            abort(422, $message);
                        }
                        if ($record instanceof ScheduleSplit) {
                            abort_unless(in_array($row->status, Schedule::PLOTTING_EDITABLE_STATUSES, true), 422, 'This schedule is locked at its current approval stage. Recall it before restoring split metadata.');
                        } elseif ($row->trashed()) {
                            $row->restore();
                            $this->audit($request, 'schedules', $row);
                        }
                    }
                    if ($record instanceof ScheduleSplit) {
                        ScheduleSplit::onlyTrashed()->whereKey($record->id)->lockForUpdate()->firstOrFail()->restore();
                        $this->audit($request, 'schedule-splits', $record);
                    }
                    $rows = Schedule::whereIn('id', $ids)->get();
                    $engine = app(\App\Services\Scheduling\Engine\RuleEngine::class);
                    $violations = [];
                    foreach ($rows as $row) {
                        if ($row->split === null && ($row->is_hybrid
                            || \App\Services\Scheduling\Support\SchedulingPolicy::isFixedMeetingPattern($row->preferred_pattern)
                            || \App\Services\Scheduling\Support\SchedulingPolicy::consecutiveDayCount($row->preferred_pattern) !== null)) {
                            $violations[] = ['rule' => 'meeting_group_required', 'message' => 'This meeting pattern requires its linked split metadata before restoration.'];
                        }
                        $violations = [...$violations, ...$engine->validate([...$row->toArray(), 'ignore_schedule_id' => $row->id])];
                    }
                    $groups = $rows->pluck('split_group_id')->filter()->unique()->all();
                    $partners = Schedule::whereHas('split', fn ($query) => $query->whereIn('split_group_id', $groups))->get();
                    $violations = [...$violations, ...$engine->validateConfiguredMeetingGroups($partners->toArray())];
                    if ($violations !== []) {
                        throw new \App\Exceptions\ScheduleConflictException($violations, 'This class cannot be restored under the current scheduling rules. Resolve the listed violations first.');
                    }
                    foreach ($rows->pluck('semester_id')->unique() as $semesterId) {
                        $cases = $this->conflicts->scan((int) $semesterId, onlyScheduleIds: $ids);
                        if ($cases !== []) {
                            abort(422, 'This class cannot be restored: it would cause a '.str_replace('_', ' ', (string) $cases[0]->rule).' conflict with the current timetable. Move or remove the clashing class first.');
                        }
                    }
                });
            });
        } catch (\App\Exceptions\ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        } catch (QueryException) {
            return response()->json(['message' => 'This record cannot be restored because an active record now uses the same unique value.'], 422);
        }
        ApiCache::forgetGroups(['departments.index', 'rooms.index', 'faculty.index', 'courses.index', 'semesters.index', 'semesters.active', 'initial.data', 'instructor_assignments.index']);

        return response()->json(['message' => 'Record restored successfully.']);
    }

    private function designationRestoreBlock(Designation $designation): ?string
    {
        if ($designation->parent_id !== null && Designation::onlyTrashed()->whereKey($designation->parent_id)->exists()) {
            return 'Restore its parent designation from Archives first.';
        }

        $nameTaken = Designation::query()
            ->where('name', $designation->name)
            ->when(
                $designation->parent_id === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $designation->parent_id),
            )
            ->exists();

        return $nameTaken ? 'A designation with this name already exists here. Rename it first.' : null;
    }

    private function archivedParent(Model $record): ?string
    {
        $departmentColumns = $record instanceof Course
            ? ['department_id', 'teaching_department_id'] : ['department_id'];
        foreach ($departmentColumns as $column) {
            $id = $record->getAttribute($column);
            if ($id !== null && ($department = Departments::onlyTrashed()->find($id)) !== null) {
                return "{$department->department_name} department";
            }
        }

        $programColumns = match (true) {
            $record instanceof Program => [],
            $record instanceof Rooms => ['home_program_id'],
            $record instanceof Course => ['program_id', 'teaching_program_id', 'teaching_source_program_id'],
            default => ['program_id'],
        };
        foreach ($programColumns as $column) {
            $id = $record->getAttribute($column);
            if ($id !== null && ($program = Program::onlyTrashed()->find($id)) !== null) {
                return "{$program->code} program";
            }
        }

        return null;
    }

    private function scheduleRestoreBlock(Schedule $schedule): ?string
    {
        $semester = Semester::find($schedule->semester_id);
        if ($semester === null) {
            return 'Restore the semester from Archives first.';
        }
        if (! $semester->is_active) {
            return 'This class belongs to a semester that is no longer active, so it cannot be restored.';
        }
        if (! Sections::whereKey($schedule->section_id)->exists()) {
            return 'The section this class belonged to no longer exists.';
        }
        if (Course::onlyTrashed()->whereKey($schedule->course_id)->exists()) {
            return 'Restore the course from Archives first.';
        }
        if (Rooms::onlyTrashed()->whereKey($schedule->room_id)->exists()) {
            return 'Restore the room from Archives first.';
        }
        if ($schedule->faculty_id !== null && Faculty::onlyTrashed()->whereKey($schedule->faculty_id)->exists()) {
            return 'Restore the instructor from Archives first.';
        }

        return null;
    }

    private function audit(Request $request, string $type, Model $record): void
    {
        $department = $record instanceof Departments ? $record->getKey() : $record->getAttribute('department_id');

        SchedulingAuditLog::create([
            'user_id' => $request->user()?->id,
            'semester_id' => $record instanceof Schedule ? $record->semester_id : null,
            'department_id' => $department,
            'action' => 'record_restored',
            'metadata' => [
                'type' => $type,
                'id' => (int) $record->getKey(),
                'label' => $this->label($type, $record, $this->scheduleLookups($type, collect([$record]))),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @return array<string, Collection<int|string, mixed>>
     */
    private function scheduleLookups(string $type, Collection $rows): array
    {
        if (! in_array($type, ['schedules', 'schedule-splits'], true)) {
            return [];
        }

        $schedules = $type === 'schedules'
            ? $rows
            : Schedule::withTrashed()->whereIn('id', $rows->pluck('schedule_id'))->get();

        return [
            'schedules' => $schedules->keyBy('id'),
            'courses' => Course::withTrashed()->whereIn('id', $schedules->pluck('course_id'))->pluck('course_code', 'id'),
            'sections' => Sections::whereIn('id', $schedules->pluck('section_id'))->pluck('section_name', 'id'),
            'rooms' => Rooms::withTrashed()->whereIn('id', $schedules->pluck('room_id'))->pluck('room_code', 'id'),
            'semesters' => Semester::withTrashed()->whereIn('id', $schedules->pluck('semester_id'))->get()->keyBy('id'),
        ];
    }

    /** @param  array<string, Collection<int|string, mixed>>  $lookups */
    private function scheduleText(?Model $schedule, array $lookups): string
    {
        if ($schedule === null) {
            return 'Unknown class';
        }

        $when = ucfirst((string) $schedule->day).' '.substr((string) $schedule->start_time, 0, 5).'-'.substr((string) $schedule->end_time, 0, 5);

        return sprintf(
            '%s · %s · %s · %s',
            $lookups['courses'][$schedule->course_id] ?? 'Course #'.$schedule->course_id,
            $lookups['sections'][$schedule->section_id] ?? 'Section removed',
            $when,
            $lookups['rooms'][$schedule->room_id] ?? 'No room',
        );
    }

    /**
     * @param  Collection<int, string>  $departments  department code by id
     * @param  array<string, Collection<int|string, mixed>>  $lookups
     */
    private function context(string $type, Model $record, Collection $departments, array $lookups): ?string
    {
        $parent = $type === 'schedule-splits' ? ($lookups['schedules'][$record->getAttribute('schedule_id')] ?? null) : null;
        $department = $departments[($parent ?? $record)->getAttribute('department_id')] ?? null;

        return match ($type) {
            'users' => trim(str_replace('_', ' ', ucfirst((string) $record->getAttribute('role'))).($department ? " · {$department}" : '')),
            'schedules' => $this->scheduleContext($record, $department, $lookups),
            'schedule-splits' => $this->scheduleContext($parent, $department, $lookups),
            default => $department,
        };
    }

    /** @param  array<string, Collection<int|string, mixed>>  $lookups */
    private function scheduleContext(?Model $schedule, ?string $department, array $lookups): ?string
    {
        $semester = $schedule === null ? null : ($lookups['semesters'][$schedule->semester_id] ?? null);
        $term = $semester === null ? null : trim($semester->academic_year.' '.$semester->semester);

        return collect([$department, $term])->filter()->implode(' · ') ?: null;
    }

    /** @param  array<string, Collection<int|string, mixed>>  $lookups */
    private function label(string $type, Model $record, array $lookups = []): string
    {
        return match ($type) {
            'users' => (string) $record->getAttribute('name'),
            'departments' => (string) $record->getAttribute('department_name'),
            'programs' => trim((string) $record->getAttribute('code').' - '.(string) $record->getAttribute('name')),
            'rooms' => (string) $record->getAttribute('room_code'),
            'faculties' => trim((string) $record->getAttribute('first_name').' '.(string) $record->getAttribute('last_name')),
            'designations' => (string) $record->getAttribute('name'),
            'courses' => trim((string) $record->getAttribute('course_code').' - '.(string) $record->getAttribute('course_name')),
            'semesters' => trim((string) $record->getAttribute('academic_year').' '.(string) $record->getAttribute('semester')),
            'schedules' => $this->scheduleText($record, $lookups),
            'schedule-splits' => 'Meeting of '.$this->scheduleText($lookups['schedules'][$record->getAttribute('schedule_id')] ?? null, $lookups),
        };
    }
}
