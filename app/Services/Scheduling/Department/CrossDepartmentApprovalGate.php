<?php

namespace App\Services\Scheduling\Department;

use App\Models\Sections;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;

/**
 * Course Assignment (handing a course to another college to teach) opens only
 * after every schedule of the acting department has received final VPAA
 * approval.
 *
 * "Approved" is whatever {@see DepartmentScheduleStatusDeriver} reports as
 * `approved`, so pending, returned and rejected sections all keep it closed.
 */
class CrossDepartmentApprovalGate
{
    public const MESSAGE = 'Cross-Department assignments will be available once all schedules are approved by the VPAA.';

    public const ERROR_CODE = 'cross_department_awaiting_vpaa_approval';

    public function __construct(private readonly DepartmentScheduleStatusDeriver $statusDeriver) {}

    /**
     * @param  list<string>  $sectionStatuses  Derived per-section statuses.
     */
    public function allowsSectionStatuses(array $sectionStatuses): bool
    {
        return $sectionStatuses === []
            || $this->statusDeriver->derive($sectionStatuses) === 'approved';
    }

    public function isOpen(int $departmentId): bool
    {
        $activeSemesterId = Semester::query()->where('is_active', true)->value('id');

        $sectionStatuses = Sections::query()
            ->with(['schedules' => fn ($query) => $query->select('id', 'section_id', 'status')
                ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId))])
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId))
            ->get(['id'])
            ->map(fn (Sections $section): string => $this->statusDeriver->derive(
                $section->schedules->pluck('status')->all(),
            ))
            ->all();

        return $this->allowsSectionStatuses($sectionStatuses);
    }

    /**
     * Refusal for a Course Assignment write by a user of `$departmentId`, or
     * null when the write may proceed.
     */
    public function refusal(int $departmentId): ?JsonResponse
    {
        if ($departmentId === 0 || $this->isOpen($departmentId)) {
            return null;
        }

        return response()->json([
            'message' => self::MESSAGE,
            'error_code' => self::ERROR_CODE,
        ], 422);
    }
}
