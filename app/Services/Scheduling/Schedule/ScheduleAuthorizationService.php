<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes schedule ownership and teaching-assignment authorization.
 * Ownership controls timetable mutations; teaching department controls faculty assignment.
 */
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

    /** Statuses awaiting the VPAA's own decision; readable only on the approval queue. */
    private const PENDING_VPAA_STATUSES = ['approved_by_dean', 'conditionally_approved'];

    /**
     * The schedule statuses the requester may read, or null for no restriction.
     *
     * Only the VPAA is restricted: its portal shows the approved timetable, so
     * anything the VPAA has not approved yet - a department draft, a submission
     * with the Dean, a Dean-approved cohort awaiting VPAA action - is not
     * readable there. Department users keep seeing their own work in progress.
     *
     * The one exception is the Schedule Approval screen, which has to show the
     * VPAA what it is being asked to approve. It opts in explicitly with
     * `?approval_queue=1`, and only an account that may actually approve gets
     * the pending rows; the flag is inert for everyone else.
     *
     * @return list<string>|null
     */
    public function visibleScheduleStatuses(Request $request): ?array
    {
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

    /**
     * The one program a Program Head works in, or null for department-wide
     * users. A Program Head without a program gets 0, which matches nothing,
     * rather than falling back to the whole department.
     */
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

    /**
     * Curricula a Program Head may see and use: its program's own plus the
     * department-wide ones (no program). A sibling program's are hidden.
     */
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

    /**
     * Rooms a Program Head may see: its program's home rooms and the shared
     * ones (no home program). Rooms homed to a sibling program are hidden.
     */
    public function scopeRoomsToProgram(Builder|EloquentBuilder $rooms, Request $request): Builder|EloquentBuilder
    {
        $scope = $this->programScope($request);

        return $scope === null ? $rooms : $rooms->where(
            fn ($room) => $room->whereNull('rooms.home_program_id')->orWhere('rooms.home_program_id', $scope),
        );
    }

    /**
     * Meetings a Program Head may see: its own program's (written into its
     * sections, or its program's and delegated courses), plus any meeting held
     * in a shared room, whichever program it belongs to.
     */
    public function scopeSchedulesToProgram(EloquentBuilder $schedules, Request $request): EloquentBuilder
    {
        $scope = $this->programScope($request);

        return $scope === null ? $schedules : $schedules->where(
            fn (EloquentBuilder $visible) => $visible
                ->where('schedules.program_id', $scope)
                ->orWhereHas('section', fn (EloquentBuilder $section) => $section->where('program_id', $scope))
                ->orWhereHas('course', fn (EloquentBuilder $course) => $course
                    ->where('program_id', $scope)
                    ->orWhere('teaching_program_id', $scope))
                ->orWhereIn('schedules.room_id', DB::table('rooms')->whereNull('home_program_id')->select('id')),
        );
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

    /** True for unscoped users, or when the instructor is in the requester's department. */
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
     * The programs whose timetable the requester may write, inside the
     * department scope checked above.
     *
     * A program with an active Program Head is written by that Program Head
     * alone; the department Secretary writes every program that has none, so
     * authority moves back to the Secretary the moment a Program Head is
     * deactivated or unassigned, and away again when one is assigned. The Dean
     * and the VPAA review and approve -- they write no program.
     *
     * @return list<int>
     */
    public function writableProgramIds(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        // Cached on the request, not the service: a controller (and the
        // service it holds) outlives one request, and a Program Head assigned
        // between two requests must change the answer.
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
            'secretary' => Program::query()
                ->where('department_id', $user->department_id)
                // Same slot test as User::activeRoleHolder('program_head', ...).
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

    public function scheduleIsWritable(Request $request, Schedule $schedule): bool
    {
        return $this->scheduleIdsWritable($request, [(int) $schedule->id]);
    }

    /**
     * Every listed schedule row belongs to a program the requester owns. A
     * row's program is its own, or its section's when the row has none (rows
     * written before programs were recorded, or whose program was deleted).
     */
    public function scheduleIdsWritable(Request $request, array $scheduleIds): bool
    {
        return $this->rowsWritable($request, DB::table('schedules')
            ->leftJoin('sections', 'sections.id', '=', 'schedules.section_id')
            ->whereIn('schedules.id', $this->normalizeIds($scheduleIds))
            ->selectRaw('COALESCE(schedules.program_id, sections.program_id) as program_id, schedules.department_id as department_id'));
    }

    /** Every listed section belongs to a program the requester owns. */
    public function sectionIdsWritable(Request $request, array $sectionIds): bool
    {
        return $this->rowsWritable($request, DB::table('sections')
            ->whereIn('id', $this->normalizeIds($sectionIds))
            ->select(['program_id', 'department_id']));
    }

    private function rowsWritable(Request $request, Builder $rows): bool
    {
        $user = $request->user();

        return $rows->get()->every(function (object $row) use ($request, $user): bool {
            if ($row->program_id !== null) {
                return $this->programIsWritable($request, (int) $row->program_id);
            }

            // A row no program claims is department-level work, which is the
            // Secretary's.
            return $user?->role === 'secretary'
                && $user->is_active
                && $user->department_id !== null
                && (int) $row->department_id === (int) $user->department_id;
        });
    }

    /** @return list<int> */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, static fn ($id) => $id !== null && $id !== ''))));
    }

    /**
     * Assignment follows the course teaching department, not always timetable ownership.
     */
    public function scheduleIdsAssignableByDepartment(Request $request, array $scheduleIds): bool
    {
        $scope = $this->departmentScope($request);
        if ($scope === null || $scheduleIds === []) {
            return true;
        }

        $delegatedHere = Course::query()
            ->where('teaching_department_id', $scope)
            ->pluck('id')
            ->map('intval')
            ->all();
        $delegatedElsewhere = Course::query()
            ->whereNotNull('teaching_department_id')
            ->where('teaching_department_id', '!=', $scope)
            ->pluck('id')
            ->map('intval')
            ->all();

        return ! Schedule::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $scheduleIds))))
            ->where(function ($query) use ($scope, $delegatedHere, $delegatedElsewhere): void {
                $query->when($delegatedHere !== [], fn ($q) => $q->where(
                    fn ($scoped) => $scoped->whereNull('course_id')->orWhereNotIn('course_id', $delegatedHere)
                ));
                $query->where(function ($foreign) use ($scope, $delegatedElsewhere): void {
                    $foreign->where('department_id', '!=', $scope)
                        ->when($delegatedElsewhere !== [], fn ($q) => $q->orWhereIn('course_id', $delegatedElsewhere));
                });
            })
            ->exists();
    }
}
