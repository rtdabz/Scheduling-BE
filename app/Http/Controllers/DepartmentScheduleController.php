<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Sections;
use App\Models\Schedule;
use App\Models\Terms;
use App\Services\SystemNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentScheduleController extends Controller
{
    public function __construct(private readonly SystemNotificationService $notifications)
    {
    }

    private function activeTermId(): ?int
    {
        return Terms::where('is_active', true)->value('id');
    }

    private function departmentSectionIds(int $departmentId): array
    {
        $query = Sections::where('department_id', $departmentId)
            ->where('status', 'active');

        $activeTermId = $this->activeTermId();
        if ($activeTermId) {
            $query->where('term_id', $activeTermId);
        }

        return $query->pluck('id')->toArray();
    }

    private function departmentScheduleQuery(int $departmentId)
    {
        $query = Schedule::whereIn('section_id', $this->departmentSectionIds($departmentId));

        $activeTermId = $this->activeTermId();
        if ($activeTermId) {
            $query->where('term_id', $activeTermId);
        }

        return $query;
    }

    private function ensureRoleCanActOnDepartment(Request $request, int $departmentId, array $roles): ?JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->role !== 'vpaa' && (int) $user->department_id !== $departmentId) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return null;
    }

    /**
     * Derive a single "section-level status" from all schedule rows
     * belonging to that section. Uses the most conservative (lowest-ranked)
     * status present. If the section has no schedules at all it is 'draft'.
     *
     * Status rank (0 = earliest / most conservative):
     *   draft < completed < submitted < approved_by_dean < approved
     */
    private function deriveStatus(array $scheduleStatuses): string
    {
        $rank = [
            'draft'            => 0,
            'completed'        => 1,
            'submitted'        => 2,
            'approved_by_dean' => 3,
            'approved'         => 4,
        ];

        if (empty($scheduleStatuses)) {
            return 'draft';
        }

        $minRank = PHP_INT_MAX;
        $result  = 'draft';

        foreach ($scheduleStatuses as $raw) {
            // Normalise extended statuses to the 4 canonical ones
            $normalised = match (true) {
                in_array($raw, ['faculty_assignment', 'finalized']) => 'approved',
                $raw === 'approved_by_dean'                         => 'approved_by_dean',
                $raw === 'submitted'                                => 'submitted',
                $raw === 'completed'                                => 'completed',
                default                                             => 'draft', // draft, rejected, rejected_by_dean
            };

            $r = $rank[$normalised] ?? 0;
            if ($r < $minRank) {
                $minRank = $r;
                $result  = $normalised;
            }
        }

        return $result;
    }

    /**
     * GET /api/departments/{id}/schedule-status
     *
     * Returns every section in the department together with its derived
     * schedule status, grouped so the frontend can build the 4-stage counts
     * and per-year-level checklist without extra round-trips.
     */
    public function scheduleStatus(int $id): JsonResponse
    {
        $department = Departments::findOrFail($id);

        // Load all active sections for this department, eager-load their schedules
        $sections = Sections::with('schedules:id,section_id,status')
            ->where('department_id', $id)
            ->where('status', 'active')
            ->orderBy('year_level')
            ->orderBy('section_name')
            ->get();

        $result = $sections->map(function (Sections $section) {
            $rawStatuses = $section->schedules->pluck('status')->toArray();
            $derived     = $this->deriveStatus($rawStatuses);

            return [
                'id'         => $section->id,
                'code'       => $section->section_name,
                'year_level' => (int) $section->year_level,
                'status'     => $derived,
            ];
        });

        return response()->json([
            'department_id'   => $department->id,
            'department_name' => $department->department_name,
            'sections'        => $result->values(),
            'department_status' => $this->deriveStatus($result->pluck('status')->toArray()),
        ]);
    }

    /**
     * POST /api/departments/{id}/submit-schedules
     *
     * Gating rule (enforced here, also enforced on the frontend):
     * All year levels the department offers must have ZERO sections
     * still in 'draft' status before a bulk submit is allowed.
     *
     * If gating passes, all 'completed' and 'rejected'/'rejected_by_dean'
     * schedule rows for this department are set to 'submitted'.
     *
     * RBAC: only vpaa, or dean/secretary whose department_id matches.
     */
    public function submitSchedules(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        // RBAC check — vpaa can submit any department;
        // dean/secretary can only submit their own department.
        $allowed =
            $user->role === 'vpaa' ||
            (in_array($user->role, ['dean', 'secretary', 'program_head']) &&
             (int) $user->department_id === $id);

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $department = Departments::findOrFail($id);

        // ── Gating rule: re-derive section statuses server-side ──
        $sections = Sections::with('schedules:id,section_id,status')
            ->where('department_id', $id)
            ->where('status', 'active')
            ->get();

        // Group by year_level and check if any year level has draft sections
        $yearLevels = $sections->groupBy('year_level');

        $blockedYears = [];
        foreach ($yearLevels as $yearLevel => $secs) {
            foreach ($secs as $sec) {
                $rawStatuses = $sec->schedules->pluck('status')->toArray();
                $derived     = $this->deriveStatus($rawStatuses);
                if ($derived === 'draft') {
                    $blockedYears[] = (int) $yearLevel;
                    break;
                }
            }
        }

        $blockedYears = array_unique($blockedYears);

        if (! empty($blockedYears)) {
            sort($blockedYears);
            $yearLabels = array_map(fn($y) => "{$y}th year", $blockedYears);
            return response()->json([
                'message'      => 'Cannot submit: some year levels still have sections in draft.',
                'blocked_years' => $blockedYears,
                'hint'         => 'Finish drafting ' . implode(', ', $yearLabels) . ' before submitting.',
            ], 422);
        }

        // ── Perform the bulk-submit ──
        $sectionIds = $sections->pluck('id')->toArray();

        $updated = Schedule::whereIn('section_id', $sectionIds)
            ->whereIn('status', ['completed', 'rejected', 'rejected_by_dean'])
            ->update([
                'status'     => 'submitted',
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $term = Terms::query()->find($this->activeTermId());
            $this->notifications->notifyRoles(
                ['dean', 'secretary', 'program_head'],
                'schedule_submitted',
                'Schedule submitted for Dean review',
                $this->notifications->departmentWorkflowMessage(
                    'submitted',
                    $department,
                    $term,
                    $user,
                    $updated,
                ),
                $user,
                $department->id,
                $term?->id,
                null,
                ['schedules_updated' => $updated],
            );
        }

        return response()->json([
            'message'          => 'Department schedules submitted for dean approval.',
            'department_name'  => $department->department_name,
            'schedules_updated' => $updated,
        ]);
    }

    public function approveByDean(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['dean', 'vpaa'])) {
            return $forbidden;
        }

        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();

        $updated = DB::transaction(function () use ($id, $user, $now) {
            return $this->departmentScheduleQuery($id)
                ->where('status', 'submitted')
                ->update([
                    'status' => 'approved_by_dean',
                    'reviewed_by_dean' => $user->id,
                    'reviewed_at_dean' => $now,
                    'rejection_reason' => null,
                    'updated_at' => $now,
                ]);
        });

        if ($updated > 0) {
            $term = Terms::query()->find($this->activeTermId());
            $this->notifications->notifyRoles(
                ['vpaa', 'dean', 'secretary', 'program_head'],
                'schedule_approved_by_dean',
                'Dean approved department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'approved and forwarded',
                    $department,
                    $term,
                    $user,
                    $updated,
                ),
                $user,
                $department->id,
                $term?->id,
                null,
                ['schedules_updated' => $updated],
            );
        }

        return response()->json([
            'message' => 'Department schedule approved by Dean and forwarded to VPAA.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
        ]);
    }

    public function returnByDean(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['dean', 'vpaa'])) {
            return $forbidden;
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();

        $updated = DB::transaction(function () use ($id, $user, $now, $validated) {
            return $this->departmentScheduleQuery($id)
                ->where('status', 'submitted')
                ->update([
                    'status' => 'rejected_by_dean',
                    'rejection_reason' => $validated['rejection_reason'],
                    'reviewed_by_dean' => $user->id,
                    'reviewed_at_dean' => $now,
                    'updated_at' => $now,
                ]);
        });

        if ($updated > 0) {
            $term = Terms::query()->find($this->activeTermId());
            $this->notifications->notifyRoles(
                ['dean', 'secretary', 'program_head'],
                'schedule_returned_by_dean',
                'Dean returned department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'returned',
                    $department,
                    $term,
                    $user,
                    $updated,
                    $validated['rejection_reason'],
                ),
                $user,
                $department->id,
                $term?->id,
                $validated['rejection_reason'],
                ['schedules_updated' => $updated],
            );
        }

        return response()->json([
            'message' => 'Department schedule returned by Dean for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
        ]);
    }

    public function approveByVpaa(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['vpaa'])) {
            return $forbidden;
        }

        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();

        $updated = DB::transaction(function () use ($id, $user, $now) {
            return $this->departmentScheduleQuery($id)
                ->where('status', 'approved_by_dean')
                ->update([
                    'status' => 'faculty_assignment',
                    'approved_by_vpaa' => $user->id,
                    'approved_at_vpaa' => $now,
                    'rejection_reason' => null,
                    'updated_at' => $now,
                ]);
        });

        if ($updated > 0) {
            $term = Terms::query()->find($this->activeTermId());
            $this->notifications->notifyRoles(
                ['vpaa', 'dean', 'secretary', 'program_head'],
                'schedule_approved_by_vpaa',
                'VPAA approved department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'approved',
                    $department,
                    $term,
                    $user,
                    $updated,
                ),
                $user,
                $department->id,
                $term?->id,
                null,
                ['schedules_updated' => $updated],
            );
        }

        return response()->json([
            'message' => 'Department schedule approved by VPAA.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
        ]);
    }

    public function returnByVpaa(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['vpaa'])) {
            return $forbidden;
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();

        $updated = DB::transaction(function () use ($id, $user, $now, $validated) {
            return $this->departmentScheduleQuery($id)
                ->where('status', 'approved_by_dean')
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $validated['rejection_reason'],
                    'approved_by_vpaa' => $user->id,
                    'approved_at_vpaa' => $now,
                    'updated_at' => $now,
                ]);
        });

        if ($updated > 0) {
            $term = Terms::query()->find($this->activeTermId());
            $this->notifications->notifyRoles(
                ['vpaa', 'dean', 'secretary', 'program_head'],
                'schedule_returned_by_vpaa',
                'VPAA returned department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'returned',
                    $department,
                    $term,
                    $user,
                    $updated,
                    $validated['rejection_reason'],
                ),
                $user,
                $department->id,
                $term?->id,
                $validated['rejection_reason'],
                ['schedules_updated' => $updated],
            );
        }

        return response()->json([
            'message' => 'Department schedule returned by VPAA for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
        ]);
    }
}
