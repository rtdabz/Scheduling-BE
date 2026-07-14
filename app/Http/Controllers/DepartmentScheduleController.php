<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Sections;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentScheduleController extends Controller
{
    /**
     * Derive a single "section-level status" from all schedule rows
     * belonging to that section. Uses the most conservative (lowest-ranked)
     * status present. If the section has no schedules at all it is 'draft'.
     *
     * Status rank (0 = earliest / most conservative):
     *   draft < submitted < approved_by_dean < approved
     */
    private function deriveStatus(array $scheduleStatuses): string
    {
        $rank = [
            'draft'            => 0,
            'submitted'        => 1,
            'approved_by_dean' => 2,
            'approved'         => 3,
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
        ]);
    }

    /**
     * POST /api/departments/{id}/submit-schedules
     *
     * Gating rule (enforced here, also enforced on the frontend):
     * All year levels the department offers must have ZERO sections
     * still in 'draft' status before a bulk submit is allowed.
     *
     * If gating passes, all 'draft' and 'rejected'/'rejected_by_dean'
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
            (in_array($user->role, ['dean', 'secretary']) &&
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
            ->whereIn('status', ['draft', 'rejected', 'rejected_by_dean'])
            ->update([
                'status'     => 'submitted',
                'updated_at' => now(),
            ]);

        return response()->json([
            'message'          => 'Department schedules submitted for dean approval.',
            'department_name'  => $department->department_name,
            'schedules_updated' => $updated,
        ]);
    }
}
