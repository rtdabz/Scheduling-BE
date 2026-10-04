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
            'secretary' => Program::query()
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
        $user = $request->user();

        return $rows->get()->every(function (object $row) use ($request, $user): bool {
            if ($row->program_id !== null) {
                return $this->programIsWritable($request, (int) $row->program_id);
            }

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
