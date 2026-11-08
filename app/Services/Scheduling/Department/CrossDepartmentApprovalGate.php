<?php

namespace App\Services\Scheduling\Department;

use App\Models\ScheduleSubmission;
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

    /**
     * Whether every active section of the department has final VPAA approval in
     * the given semester (the active one when omitted). Room borrowing reuses this.
     */
    public function isOpen(int $departmentId, ?int $semesterId = null): bool
    {
        $activeSemesterId = $semesterId ?? Semester::query()->where('is_active', true)->value('id');

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
     * The department was VPAA-approved this semester but no longer is: it recalled some or
     * all sections (or added new ones) and awaits VPAA approval again. Cross-Department hides
     * it from teaching colleges, and room borrowing treats it as updating its schedules.
     */
    public function isAwaitingReapproval(int $departmentId, ?int $semesterId = null): bool
    {
        $semesterId ??= Semester::query()->where('is_active', true)->value('id');
        if ($semesterId === null || $this->isOpen($departmentId, (int) $semesterId)) {
            return false;
        }

        return ScheduleSubmission::query()
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->whereNotNull('vpaa_reviewed_at')
            ->exists();
    }

    /**
     * Of the given departments, those awaiting VPAA re-approval.
     *
     * @param  iterable<int|string>  $departmentIds
     * @return list<int>
     */
    public function awaitingReapproval(iterable $departmentIds, int $semesterId): array
    {
        return collect($departmentIds)
            ->map(static fn (int|string $id): int => (int) $id)
            ->unique()
            ->filter(fn (int $id): bool => $this->isAwaitingReapproval($id, $semesterId))
            ->values()
            ->all();
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
