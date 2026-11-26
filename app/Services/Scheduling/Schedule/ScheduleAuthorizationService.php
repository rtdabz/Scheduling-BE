<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ScheduleAuthorizationService
{
    public function departmentHasProgram(int $departmentId): bool
    {
        return Program::query()->where('department_id', $departmentId)->exists();
    }

    public function departmentScope(Request $request): ?int
    {
        $user = $request->user();
        if ($user === null || $user->isVpaa() || $user->department_id === null) {
            return null;
        }

        return (int) $user->department_id;
    }

    private const PENDING_VPAA_STATUSES = ['approved_by_dean', 'conditionally_approved'];

    /**
     * @return list<string>|null
     */
    public function visibleScheduleStatuses(Request $request): ?array
    {
        // Published tables and Gantt views show the VPAA-approved timetable for every role. Approval queue access
        // must not widen this view to include schedules that are still being reviewed.
        if (in_array($request->query('view'), ['gantt', 'published'], true)) {
            return SchedulingPolicy::VPAA_VISIBLE_STATUSES;
        }

        $user = $request->user();
        if ($user?->isVpaa() !== true) {
            return null;
        }

        if ($request->boolean('approval_queue') && $user->hasCapability('schedule.approve_vpaa')) {
            return array_merge(SchedulingPolicy::VPAA_VISIBLE_STATUSES, self::PENDING_VPAA_STATUSES);
        }

        return SchedulingPolicy::VPAA_VISIBLE_STATUSES;
    }

    public function payloadBelongsToDepartment(Request $request, int $departmentId): bool
    {
        $scope = $this->departmentScope($request);

        return $scope === null || $scope === $departmentId;
    }

    public function programScope(Request $request): ?int
    {
        $user = $request->user();
        if ($user?->role !== 'program_head') {
            return null;
        }

        return (int) ($user->program_id ?? 0);
    }

    public function payloadBelongsToProgram(Request $request, mixed $programId): bool
    {
        $scope = $this->programScope($request);

        return $scope === null || ($programId !== null && $programId !== '' && $scope === (int) $programId);
    }

    public function scopeCurriculaToProgram(EloquentBuilder $curricula, Request $request): EloquentBuilder
    {
        $scope = $this->programScope($request);

        return $scope === null ? $curricula : $curricula->where(
            fn (EloquentBuilder $curriculum) => $curriculum->whereNull('curriculum.program_id')->orWhere('curriculum.program_id', $scope),
        );
    }

    public function curriculumBelongsToProgram(Request $request, mixed $curriculumProgramId): bool
    {
        return $curriculumProgramId === null || $this->payloadBelongsToProgram($request, $curriculumProgramId);
    }

    public function scopeRoomsToProgram(Builder|EloquentBuilder $rooms, Request $request): Builder|EloquentBuilder
    {
        $scope = $this->programScope($request);

        return $scope === null ? $rooms : $rooms->where(
            fn ($room) => $room->whereNull('rooms.home_program_id')->orWhere('rooms.home_program_id', $scope),
        );
    }

    public function scopeSchedulesToProgram(EloquentBuilder $schedules, Request $request): EloquentBuilder
    {
        $scope = $this->programScope($request);

        return $scope === null ? $schedules : $schedules->where(
            fn (EloquentBuilder $visible) => $visible
                ->where('schedules.program_id', $scope)
                ->orWhereHas('section', fn (EloquentBuilder $section) => $section->where('program_id', $scope))
                ->orWhereHas('course', fn (EloquentBuilder $course) => $course->where('program_id', $scope))
                ->orWhere(fn (EloquentBuilder $handed) => $handed->handedToProgram($scope))
                ->orWhereIn('schedules.room_id', DB::table('rooms')->whereNull('home_program_id')->select('id')),
        );
    }

    public function scopeReadableSchedules(EloquentBuilder $schedules, Request $request): EloquentBuilder
    {
        $departmentId = $this->departmentScope($request);
        $statuses = $this->visibleScheduleStatuses($request);

        $schedules
            ->when($departmentId !== null, fn (EloquentBuilder $query) => $query->where(
                fn (EloquentBuilder $owned) => $owned
                    ->where('schedules.department_id', $departmentId)
                    ->orWhere(fn (EloquentBuilder $delegated) => $delegated->delegatedTo($departmentId)),
            ))
            ->when($statuses !== null, fn (EloquentBuilder $query) => $query->whereIn('schedules.status', $statuses));

        return $this->scopeSchedulesToProgram($schedules, $request);
    }

    public function requestedDepartment(Request $request, mixed $requestedDepartmentId = null): ?int
    {
        $scope = $this->departmentScope($request);
        if ($scope !== null) {
            return $scope;
        }

        return $requestedDepartmentId === null || $requestedDepartmentId === ''
            ? null
            : (int) $requestedDepartmentId;
    }

    public function rejectsRequestedDepartment(Request $request, mixed $requestedDepartmentId): bool
    {
        $scope = $this->departmentScope($request);

        return $scope !== null && $requestedDepartmentId !== null && $requestedDepartmentId !== ''
            && (int) $requestedDepartmentId !== $scope;
    }

    public function facultyBelongsToDepartment(Request $request, int $facultyId): bool
    {
        $scope = $this->departmentScope($request);

        return $scope === null || DB::table('faculties')
            ->where('id', $facultyId)
            ->where('department_id', $scope)
            ->exists();
    }

    public function scheduleBelongsToDepartment(Request $request, Schedule $schedule): bool
    {
        return $this->payloadBelongsToDepartment($request, (int) $schedule->department_id);
    }

    public function scheduleIdsBelongToDepartment(Request $request, array $scheduleIds): bool
    {
        $scope = $this->departmentScope($request);
        if ($scope === null || $scheduleIds === []) {
            return true;
        }

        return ! Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->where('department_id', '!=', $scope)
            ->exists();
    }

    public function sectionIdsBelongToDepartment(Request $request, array $sectionIds): bool
    {
        $scope = $this->departmentScope($request);
        if ($scope === null || $sectionIds === []) {
            return true;
        }

        return ! DB::table('sections')
            ->whereIn('id', array_values(array_unique(array_map('intval', $sectionIds))))
            ->where('department_id', '!=', $scope)
            ->exists();
    }

    public const PROGRAM_FORBIDDEN_MESSAGE = 'This program\'s schedule is managed by its Program Head. You can view it, but only the program\'s owner can change it.';

    /**
     * @return list<int>
     */
    public function writableProgramIds(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $cacheKey = 'writable_program_ids.'.$user->id;
        if (! $request->attributes->has($cacheKey)) {
            $request->attributes->set($cacheKey, $this->writableProgramIdsFor($user));
        }

        return $request->attributes->get($cacheKey);
    }

    /** @return list<int> */
    public function writableProgramIdsFor(User $user): array
    {
        if ($user->department_id === null || ! $user->is_active) {
            return [];
        }

        return match ($user->role) {
            'program_head' => $user->program_id !== null
                && Program::query()->whereKey($user->program_id)->where('department_id', $user->department_id)->exists()
                    ? [(int) $user->program_id]
                    : [],
            // Secretary and Director share the department office: every program without an
            // active program head, never a program head's own program.
            'secretary', 'director' => Program::query()
                ->where('department_id', $user->department_id)
                ->whereNotIn('id', User::query()
                    ->where('role', 'program_head')
                    ->where('is_active', true)
                    ->whereNotNull('program_id')
                    ->select('program_id'))
                ->pluck('id')
                ->map('intval')
                ->values()
                ->all(),
            default => [],
        };
    }

    public function programIsWritable(Request $request, ?int $programId): bool
    {
        return $programId !== null && in_array($programId, $this->writableProgramIds($request), true);
    }

    public const PROGRAM_HEAD_MANAGED_MESSAGE = 'This program is managed by its Program Head. You can view its records, but only the Program Head can change them.';

    public const PROGRAM_HEAD_MANAGED_COURSE_MESSAGE = 'This course is used by a program managed by its Program Head. You can view it, but changing it would also change that program\'s curriculum.';

    /**
     * The department office (Secretary or Director) manages only the programs without an active
     * Program Head (`writableProgramIdsFor`); a Program Head's program is view-only to it. Records
     * without a program are department-level and stay with the office. Every other role is
     * unaffected and keeps its own checks (`payloadBelongsToProgram` for Program Heads).
     */
    public function officeMayManageProgram(Request $request, mixed $programId): bool
    {
        if ($request->user()?->isSecretaryOrDirector() !== true || $programId === null || $programId === '') {
            return true;
        }

        return $this->programIsWritable($request, (int) $programId);
    }

    /**
     * Resolve persisted ownership for room reassignment and history reuse, then apply the existing
     * writable-program rule. Legacy rows fall back to their section, curriculum, then course.
     * An unresolved row cannot give the office access to a department containing headed programs.
     *
     * @param  array<int, array{department_id: int, rows: list<array<string, mixed>>}>  $scopes
     * @return array<int, bool>
     */
    public function programOperationAccessAmong(Request $request, array $scopes): array
    {
        $rows = collect($scopes)->flatMap(fn (array $scope) => $scope['rows']);
        $sections = Sections::query()->whereKey($rows->pluck('section_id')->filter()->unique())->get(['id', 'department_id', 'program_id', 'curriculum_id'])->keyBy('id');
        $curricula = Curriculum::query()->whereKey($rows->pluck('curriculum_id')->merge($sections->pluck('curriculum_id'))->filter()->unique())->get(['id', 'program_id'])->keyBy('id');
        $courses = Course::withTrashed()->whereKey($rows->pluck('course_id')->filter()->unique())->get(['id', 'program_id'])->keyBy('id');
        $unscopedAllowed = $this->headManagedProgramIds($request) === [];
        $protectedFallbackCourses = null;
        $access = [];
        foreach ($scopes as $key => $scope) {
            $access[$key] = $scope['rows'] !== [];
            foreach ($scope['rows'] as $row) {
                $section = $sections->get($row['section_id'] ?? null);
                if ((isset($row['department_id']) && (int) $row['department_id'] !== $scope['department_id'])
                    || ($section !== null && (int) $section->department_id !== $scope['department_id'])) {
                    $access[$key] = false;
                    break;
                }
                // Keep both recorded and current section ownership; a moved section must not erase
                // the historical program or allow a conflicting schedule owner to bypass protection.
                $programIds = $this->normalizeIds(array_filter([
                    $row['program_id'] ?? $curricula->get($row['curriculum_id'] ?? null)?->program_id,
                    $section?->program_id ?? $curricula->get($section?->curriculum_id)?->program_id,
                ]));
                if ($programIds === []) {
                    // A shared course cannot identify an otherwise unscoped office operation as
                    // belonging only to its catalog owner. Reuse the existing protected-course rule.
                    $protectedFallbackCourses ??= array_fill_keys($this->headManagedCourseIds($request, $courses->keys()->map(fn ($id) => (int) $id)->all(), includeArchivedCourses: true), true);
                    if (isset($protectedFallbackCourses[(int) ($row['course_id'] ?? 0)])) {
                        $access[$key] = false;
                        break;
                    }
                    $programIds = $this->normalizeIds(array_filter([$courses->get($row['course_id'] ?? null)?->program_id]));
                }
                $writable = $programIds === []
                    ? $unscopedAllowed && $this->rowWritable($request, null, $scope['department_id'])
                    : collect($programIds)->every(fn (int $id) => $this->programIsWritable($request, $id));
                if (! $writable) {
                    $access[$key] = false;
                    break;
                }
            }
        }

        return $access;
    }

    /** @param list<array<string, mixed>> $rows */
    public function programOperationRowsWritable(Request $request, array $rows, int $departmentId): bool
    {
        return $this->programOperationAccessAmong($request, [['department_id' => $departmentId, 'rows' => $rows]])[0];
    }

    /**
     * For the department office: its department's programs that have an active Program Head,
     * which it may view but not manage. Empty for every other role.
     *
     * @return list<int>
     */
    public function headManagedProgramIds(Request $request): array
    {
        $user = $request->user();
        if ($user?->isSecretaryOrDirector() !== true || $user->department_id === null) {
            return [];
        }

        return Program::query()
            ->where('department_id', $user->department_id)
            ->whereNotIn('id', $this->writableProgramIds($request))
            ->pluck('id')
            ->map('intval')
            ->values()
            ->all();
    }

    /**
     * Of the given courses, those the department office may not change because a Program Head's
     * program uses them: the course belongs to that program, is placed in one of its curricula,
     * or is placed in a shared curriculum that one of its active sections follows. A course record
     * is shared by every curriculum that places it, so a course also used by an office program is
     * still protected (editing it would change the Program Head's curriculum too).
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function headManagedCourseIds(Request $request, array $courseIds, bool $includeArchivedCourses = false): array
    {
        $programIds = $this->headManagedProgramIds($request);
        $courseIds = $this->normalizeIds($courseIds);
        if ($programIds === [] || $courseIds === []) {
            return [];
        }

        $departmentId = (int) $request->user()->department_id;
        $curriculumIds = DB::table('curriculum')
            ->where('department_id', $departmentId)
            ->where('status', '!=', 'archived')
            ->where(fn (Builder $curricula) => $curricula
                ->whereIn('program_id', $programIds)
                ->orWhere(fn (Builder $shared) => $shared
                    ->whereNull('program_id')
                    ->whereIn('id', DB::table('sections')
                        ->where('status', 'active')
                        ->whereIn('program_id', $programIds)
                        ->whereNotNull('curriculum_id')
                        ->select('curriculum_id'))))
            ->pluck('id');

        return Course::query()
            ->when($includeArchivedCourses, fn (EloquentBuilder $courses) => $courses->withTrashed())
            ->whereIn('id', $courseIds)
            ->where(fn (EloquentBuilder $used) => $used
                ->whereIn('program_id', $programIds)
                ->orWhereIn('id', DB::table('curriculum_course')
                    ->whereIn('curriculum_id', $curriculumIds)
                    ->select('course_id')))
            ->pluck('id')
            ->map('intval')
            ->sort()
            ->values()
            ->all();
    }

    public function scheduleIsWritable(Request $request, Schedule $schedule): bool
    {
        return $this->scheduleIdsWritable($request, [(int) $schedule->id]);
    }

    public function scheduleIdsWritable(Request $request, array $scheduleIds): bool
    {
        return $this->rowsWritable($request, DB::table('schedules')
            ->leftJoin('sections', 'sections.id', '=', 'schedules.section_id')
            ->whereIn('schedules.id', $this->normalizeIds($scheduleIds))
            ->selectRaw('COALESCE(schedules.program_id, sections.program_id) as program_id, schedules.department_id as department_id'));
    }

    public function sectionIdsWritable(Request $request, array $sectionIds): bool
    {
        return $this->rowsWritable($request, DB::table('sections')
            ->whereIn('id', $this->normalizeIds($sectionIds))
            ->select(['program_id', 'department_id']));
    }

    private function rowsWritable(Request $request, Builder $rows): bool
    {
        return $rows->get()->every(fn (object $row): bool => $this->rowWritable($request, $row->program_id, $row->department_id));
    }

    /**
     * Schedules the user may reassign, resolving legacy ownership in batches without a query per row.
     *
     * @param  iterable<Schedule>  $schedules
     * @return list<int>
     */
    public function writableScheduleIdsAmong(Request $request, iterable $schedules): array
    {
        $scopes = [];
        foreach ($schedules as $schedule) {
            $scopes[(int) $schedule->id] = ['department_id' => (int) $schedule->department_id, 'rows' => [$schedule->getAttributes()]];
        }

        return array_keys(array_filter($this->programOperationAccessAmong($request, $scopes)));
    }

    private function rowWritable(Request $request, mixed $programId, mixed $departmentId): bool
    {
        if ($programId !== null) {
            return $this->programIsWritable($request, (int) $programId);
        }
        $user = $request->user();

        return $user?->isSecretaryOrDirector() === true
            && $user->is_active
            && $user->department_id !== null
            && (int) $departmentId === (int) $user->department_id;
    }

    public const MAJOR_INSTRUCTOR_FORBIDDEN_MESSAGE = 'Only the Program Head can assign instructors to this program\'s major courses.';

    /**
     * The department office (Secretary or Director) may not assign or change instructors on its own
     * department's major classes when the major's program has an active Program Head. Programs without
     * a Program Head keep the office as the fallback. Minor courses and classes delegated in from
     * another department are outside this rule.
     *
     * @param  iterable<Schedule>  $schedules
     */
    public function majorInstructorAssignmentBlocked(Request $request, iterable $schedules): bool
    {
        $user = $request->user();
        if ($user === null || ! $user->isSecretaryOrDirector()) {
            return false;
        }

        $programIds = [];
        foreach ($schedules as $schedule) {
            $schedule->loadMissing('course');
            $course = SchedulingPolicy::courseForSchedule($schedule);
            if ($course === null
                || ! SchedulingPolicy::isMajorCourse($course)
                || (int) $schedule->department_id !== (int) $user->department_id) {
                continue;
            }
            // Many majors are recorded at department level (no program on the course). Those belong
            // to the program of the class itself: the schedule's program, else its section's.
            // A course delegated to another department's teachers names no program and stays out.
            $programId = SchedulingPolicy::requiredTeachingProgramId($course);
            if ($programId === null && $course->teaching_department_id === null) {
                $schedule->loadMissing('section');
                $programId = $schedule->program_id ?? $schedule->section?->program_id;
                $programId = $programId === null ? null : (int) $programId;
            }
            if ($programId !== null) {
                $programIds[$programId] = true;
            }
        }

        return $programIds !== [] && User::query()
            ->where('role', 'program_head')
            ->where('is_active', true)
            ->whereIn('program_id', array_keys($programIds))
            ->exists();
    }

    /** @return list<int> */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, static fn ($id) => $id !== null && $id !== ''))));
    }

    public function scheduleIdsAssignableByDepartment(Request $request, array $scheduleIds): bool
    {
        if (app(ProgramCourseVisibility::class)->hiddenInstructorScheduleIds(
            $request, Schedule::query()->whereIn('id', $this->normalizeIds($scheduleIds))->get(),
        ) !== []) {
            return false;
        }
        $scope = $this->departmentScope($request);
        if ($scope === null || $scheduleIds === []) {
            return true;
        }

        // Assignable, decided per class: delegated here (course- or section-level), or the
        // department's own class that is not delegated to anyone else.
        return ! Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->whereNot(fn ($assignable) => $assignable
                ->delegatedTo($scope)
                ->orWhere(fn ($own) => $own
                    ->where('schedules.department_id', $scope)
                    ->whereNot(fn ($elsewhere) => $elsewhere->delegatedToOtherThan($scope))))
            ->exists();
    }
}
