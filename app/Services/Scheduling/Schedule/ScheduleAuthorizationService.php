<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Program;
use App\Models\Schedule;
use App\Services\Scheduling\Support\SchedulingPolicy;
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
