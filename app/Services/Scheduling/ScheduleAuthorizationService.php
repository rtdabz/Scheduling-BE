<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes schedule ownership and teaching-assignment authorization.
 * Ownership controls timetable mutations; teaching department controls faculty assignment.
 */
final class ScheduleAuthorizationService
{
    public function departmentScope(Request $request): ?int
    {
        $user = $request->user();
        if ($user === null || $user->isVpaa() || $user->department_id === null) {
            return null;
        }

        return (int) $user->department_id;
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
