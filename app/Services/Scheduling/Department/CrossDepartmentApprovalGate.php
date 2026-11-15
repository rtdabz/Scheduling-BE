<?php

namespace App\Services\Scheduling\Department;

use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Course Assignment (handing a course to another college to teach) opens only
 * after final VPAA approval: for a Program Head, of their own program's
 * schedule ({@see isOpenFor()}); for the department office, of every schedule
 * of the department ({@see isOpen()}, which room borrowing and Cross-Department
 * visibility also use).
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
        return $this->allowsSectionStatuses($this->sectionStatuses($departmentId, $semesterId));
    }

    /**
     * Whether Course Assignment is open for this user. A Program Head submits only their
     * own program's sections, so their gate opens once the VPAA approves *their program's*
     * active sections — a sibling program still in Draft or review no longer holds it shut.
     * A program with no active sections this semester has nothing of its own to approve and
     * falls back to the department-wide rule. Every other role keeps the department-wide rule.
     */
    public function isOpenFor(?User $user, ?int $semesterId = null): bool
    {
        $departmentId = (int) ($user?->department_id ?? 0);
        if ($departmentId === 0) {
            return true;
        }

        $programId = $user->role === 'program_head' && $user->program_id !== null ? (int) $user->program_id : null;
        if ($programId !== null) {
            $programStatuses = $this->sectionStatuses($departmentId, $semesterId, $programId);
            if ($programStatuses !== []) {
                return $this->allowsSectionStatuses($programStatuses);
            }
        }

        return $this->isOpen($departmentId, $semesterId);
    }

    /**
     * Derived status of each active section of the department (optionally one program)
     * in the given semester (the active one when omitted).
     *
     * @return list<string>
     */
    private function sectionStatuses(int $departmentId, ?int $semesterId = null, ?int $programId = null): array
    {
        $activeSemesterId = $semesterId ?? Semester::query()->where('is_active', true)->value('id');

        return Sections::query()
            ->with(['schedules' => fn ($query) => $query->select('id', 'section_id', 'status')
                ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId))])
            ->where('department_id', $departmentId)
            ->when($programId !== null, fn ($q) => $q->where('program_id', $programId))
            ->where('status', 'active')
            ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId))
            ->get(['id'])
            ->map(fn (Sections $section): string => $this->statusDeriver->derive(
                $section->schedules->pluck('status')->all(),
            ))
            ->values()
            ->all();
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
     * Of the given source departments, those whose timetable is not yet fully VPAA-approved
     * in the semester. Cross-Department keeps their delegated classes out of view (Draft,
     * Submitted, Dean-approved, returned or recalled alike) until the VPAA approves them,
     * the same rule that opens Course Assignment.
     *
     * @param  iterable<int|string>  $departmentIds
     * @return list<int>
     */
    public function awaitingApproval(iterable $departmentIds, int $semesterId): array
    {
        return collect($departmentIds)
            ->map(static fn (int|string $id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $this->isOpen($id, $semesterId))
            ->values()
            ->all();
    }

    /**
     * Refusal for a Course Assignment write by this user, or null when the
     * write may proceed. Scoped like {@see isOpenFor()}.
     */
    public function refusal(?User $user): ?JsonResponse
    {
        if ($this->isOpenFor($user)) {
            return null;
        }

        return response()->json([
            'message' => self::MESSAGE,
            'error_code' => self::ERROR_CODE,
        ], 422);
    }
}
