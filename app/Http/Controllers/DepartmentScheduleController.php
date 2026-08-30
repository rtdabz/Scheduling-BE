<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\ScheduleHistoryRecorder;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DepartmentScheduleController extends Controller
{
    public function __construct(
        private readonly SystemNotificationService $notifications,
        private readonly ScheduleHistoryRecorder $historyRecorder,
    ) {}

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

    private function submissionForStage(
        int $departmentId,
        array $submissionStatuses,
        array $scheduleStatuses,
        string $legacyStatus,
    ): ?ScheduleSubmission {
        $termId = $this->activeTermId();
        if ($termId === null) {
            return null;
        }

        $submission = ScheduleSubmission::query()
            ->with('sections')
            ->where('department_id', $departmentId)
            ->where('term_id', $termId)
            ->whereIn('status', $submissionStatuses)
            ->latest('revision_number')
            ->first();
        if ($submission !== null) {
            return $submission;
        }

        $sectionIds = $this->departmentScheduleQuery($departmentId)
            ->whereIn('status', $scheduleStatuses)
            ->distinct()
            ->pluck('section_id')
            ->map('intval')
            ->values();
        if ($sectionIds->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($departmentId, $termId, $legacyStatus, $sectionIds): ScheduleSubmission {
            $revisionNumber = ((int) ScheduleSubmission::query()
                ->where('department_id', $departmentId)
                ->where('term_id', $termId)
                ->lockForUpdate()
                ->max('revision_number')) + 1;
            $submission = ScheduleSubmission::create([
                'department_id' => $departmentId,
                'term_id' => $termId,
                'revision_number' => $revisionNumber,
                'status' => $legacyStatus,
                'submitted_at' => now(),
            ]);
            $submission->sections()->attach($sectionIds->all(), ['state' => 'included']);

            return $submission->load('sections');
        });
    }

    private function ensureRoleCanActOnDepartment(Request $request, int $departmentId, array $roles): ?JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, $roles, true)) {
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
        // A finalized meeting belongs to a completed approval cohort. Legacy
        // duplicate draft rows must not pull that section back into drafting or
        // cause it to be included in a later revision submission.
        if (in_array('finalized', $scheduleStatuses, true)) {
            return 'approved';
        }

        $rank = [
            'draft' => 0,
            'revision' => 0,
            'completed' => 1,
            'submitted' => 2,
            'approved_by_dean' => 3,
            'conditionally_approved' => 3,
            'approved' => 4,
        ];

        if (empty($scheduleStatuses)) {
            return 'draft';
        }

        $minRank = PHP_INT_MAX;
        $result = 'draft';

        foreach ($scheduleStatuses as $raw) {
            // Normalise extended statuses to the 4 canonical ones
            $normalised = match (true) {
                in_array($raw, ['faculty_assignment', 'finalized']) => 'approved',
                $raw === 'conditionally_approved' => 'conditionally_approved',
                $raw === 'approved_by_dean' => 'approved_by_dean',
                $raw === 'submitted' => 'submitted',
                $raw === 'completed' => 'completed',
                $raw === 'revision' => 'revision',
                default => 'draft', // draft, rejected, rejected_by_dean
            };

            $r = $rank[$normalised] ?? 0;
            if ($r < $minRank) {
                $minRank = $r;
                $result = $normalised;
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
        $activeTermId = $this->activeTermId();

        $sections = Sections::with(['schedules' => function ($query) use ($activeTermId) {
            $query->select('id', 'section_id', 'status')
                ->when($activeTermId, fn ($q) => $q->where('term_id', $activeTermId));
        }])
            ->where('department_id', $id)
            ->where('status', 'active')
            ->when($activeTermId, fn ($q) => $q->where('term_id', $activeTermId))
            ->orderBy('year_level')
            ->orderBy('section_name')
            ->get();

        $result = $sections->map(function (Sections $section) {
            $rawStatuses = $section->schedules->pluck('status')->toArray();
            $derived = $this->deriveStatus($rawStatuses);

            return [
                'id' => $section->id,
                'code' => $section->section_name,
                'year_level' => (int) $section->year_level,
                'status' => $derived,
            ];
        });

        return response()->json([
            'department_id' => $department->id,
            'department_name' => $department->department_name,
            'sections' => $result->values(),
            'department_status' => $this->deriveStatus($result->pluck('status')->toArray()),
        ]);
    }

    /**
     * POST /api/departments/{id}/submit-schedules
     *
     * Initial submission requires every active section to be ready. After a
     * partial withdrawal, only the completed revision cohort is submitted;
     * finalized and already-approved cohorts remain at their current stage.
     *
     * RBAC: only VPAA, Secretary, or Program Head. Department roles may submit
     * only their own department.
     */
    public function submitSchedules(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        // VPAA can submit any department; department schedule authors can submit
        // only their own department. Dean remains review/approval-only.
        $allowed =
            $user->role === 'vpaa' ||
            (in_array($user->role, ['secretary', 'program_head']) &&
             (int) $user->department_id === $id);

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $department = Departments::findOrFail($id);
        $activeTermId = $this->activeTermId();
        $validated = $request->validate([
            'section_ids' => ['nullable', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct'],
        ]);

        $sections = Sections::with(['schedules' => function ($query) use ($activeTermId) {
            $query->select('id', 'section_id', 'status')
                ->when($activeTermId, fn ($q) => $q->where('term_id', $activeTermId));
        }])
            ->where('department_id', $id)
            ->where('status', 'active')
            ->when($activeTermId, fn ($q) => $q->where('term_id', $activeTermId))
            ->get();

        $allowedSectionIds = $sections->pluck('id')->map('intval')->values();
        $requestedSectionIds = collect($validated['section_ids'] ?? [])
            ->map('intval')
            ->unique()
            ->values();
        $invalidSectionIds = $requestedSectionIds->diff($allowedSectionIds);
        if ($invalidSectionIds->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more selected sections do not belong to this department.',
            ], 422);
        }

        $readyStatuses = ['completed', 'rejected', 'rejected_by_dean'];
        $protectedStatuses = [
            'submitted',
            'approved_by_dean',
            'conditionally_approved',
            'approved',
            'faculty_assignment',
            'finalized',
        ];
        $readySectionIds = collect();
        $protectedSectionIds = collect();
        $blockedYears = [];
        $revisionYears = [];

        foreach ($sections as $section) {
            $statuses = $section->schedules->pluck('status')->filter()->unique()->values();
            if ($statuses->isNotEmpty() && $statuses->every(
                static fn (string $status): bool => in_array($status, $readyStatuses, true)
            )) {
                $readySectionIds->push((int) $section->id);

                continue;
            }

            // A finalized or already-approved section belongs to an earlier
            // approval cohort. It remains intact while withdrawn sections go
            // through their own revision submission.
            if ($statuses->contains(
                static fn (string $status): bool => in_array($status, $protectedStatuses, true)
            )) {
                $protectedSectionIds->push((int) $section->id);

                continue;
            }

            if ($statuses->contains('revision')) {
                $revisionYears[] = (int) $section->year_level;
            }
            $blockedYears[] = (int) $section->year_level;
        }

        $readySectionIds = $readySectionIds->unique()->values();
        $protectedSectionIds = $protectedSectionIds->unique()->values();

        if ($requestedSectionIds->isNotEmpty() && $requestedSectionIds->sort()->values()->all() !== $readySectionIds->sort()->values()->all()) {
            return response()->json([
                'message' => 'The submission must include all and only the sections currently ready for approval.',
                'ready_section_ids' => $readySectionIds->all(),
            ], 422);
        }

        if ($readySectionIds->isEmpty()) {
            return response()->json([
                'message' => 'No completed or revised schedule sections are ready for submission.',
            ], 422);
        }

        if (! empty($revisionYears)) {
            $revisionYears = array_values(array_unique($revisionYears));
            sort($revisionYears);

            return response()->json([
                'message' => 'Cannot submit while withdrawn sections are still under revision.',
                'blocked_years' => $revisionYears,
            ], 422);
        }

        // Initial submission still requires the complete department. Partial
        // submission is allowed only when another cohort is already protected
        // by an active/finalized approval state.
        if ($protectedSectionIds->isEmpty() && ! empty($blockedYears)) {
            $blockedYears = array_values(array_unique($blockedYears));
            sort($blockedYears);
            $yearLabels = array_map(static fn (int $year): string => "Year {$year}", $blockedYears);

            return response()->json([
                'message' => 'Cannot submit: some year levels still have sections in draft or revision.',
                'blocked_years' => $blockedYears,
                'hint' => 'Finish '.implode(', ', $yearLabels).' before submitting the initial schedule.',
            ], 422);
        }

        $sectionIds = $readySectionIds->all();

        $result = DB::transaction(function () use ($sectionIds, $request, $department, $activeTermId, $user): array {
            $parentSubmission = ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('term_id', $activeTermId)
                ->whereIn('status', ['withdrawn', 'partially_withdrawn', 'rejected_by_dean', 'rejected_by_vpaa'])
                ->whereHas('sections', fn ($query) => $query->whereIn('sections.id', $sectionIds))
                ->latest('revision_number')
                ->first();
            $revisionNumber = ((int) ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('term_id', $activeTermId)
                ->lockForUpdate()
                ->max('revision_number')) + 1;
            $submission = ScheduleSubmission::create([
                'department_id' => $department->id,
                'term_id' => $activeTermId,
                'parent_submission_id' => $parentSubmission?->id,
                'revision_number' => $revisionNumber,
                'status' => 'pending_dean',
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);
            $submission->sections()->attach($sectionIds, ['state' => 'included']);

            $updated = Schedule::whereIn('section_id', $sectionIds)
                ->whereIn('status', ['completed', 'rejected', 'rejected_by_dean'])
                ->update([
                    'status' => 'submitted',
                    'updated_at' => now(),
                ]);
            if ($updated > 0) {
                $this->recordWorkflowAudit($request, 'schedule_submitted', $department->id, $activeTermId, [
                    'schedules_updated' => $updated,
                    'selected_section_ids' => $sectionIds,
                ], $submission->id);
            }

            return compact('updated', 'submission');
        });
        $updated = $result['updated'];
        $submission = $result['submission'];

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
                [
                    'schedules_updated' => $updated,
                    'selected_section_ids' => $sectionIds,
                    'schedule_submission_id' => $submission->id,
                ],
            );
        }

        return response()->json([
            'message' => 'Department schedules submitted for dean approval.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submission->id,
        ]);
    }

    public function approveByDean(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['dean', 'vpaa'])) {
            return $forbidden;
        }

        $validated = $request->validate([
            'override_room_tba' => ['sometimes', 'boolean'],
            'override_reason' => ['required_if:override_room_tba,true', 'nullable', 'string', 'max:2000'],
        ]);
        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();
        $submission = $this->submissionForStage($id, ['pending_dean'], ['submitted'], 'pending_dean');
        if ($submission === null) {
            return response()->json(['message' => 'No schedule submission is pending Dean approval.'], 422);
        }
        $targetSectionIds = $submission->sections->pluck('id')->map('intval')->values()->all();

        $override = (bool) ($validated['override_room_tba'] ?? false);
        $updated = DB::transaction(function () use ($id, $user, $now, $override, $validated, $submission, $targetSectionIds) {
            $updated = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $targetSectionIds)
                ->where('status', 'submitted')
                ->update([
                    'status' => $override ? 'conditionally_approved' : 'approved_by_dean',
                    'updated_at' => $now,
                ]);
            $submission->update([
                'status' => 'pending_vpaa',
                'dean_reviewed_by' => $user->id,
                'dean_reviewed_at' => $now,
                'rejection_reason' => null,
                'approval_override' => $override,
                'approval_override_reason' => $override ? ($validated['override_reason'] ?? null) : null,
            ]);

            return $updated;
        });

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_approved_by_dean', $department->id, $this->activeTermId(), [
                'schedules_updated' => $updated,
                'selected_section_ids' => $targetSectionIds,
                'approval_override' => $override,
                'approval_override_reason' => $override ? ($validated['override_reason'] ?? null) : null,
            ], $submission->id);
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
                ['schedules_updated' => $updated, 'schedule_submission_id' => $submission->id],
            );
        }

        return response()->json([
            'message' => $override
                ? 'Department schedule conditionally approved with Room TBA override.'
                : 'Department schedule approved by Dean and forwarded to VPAA.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submission->id,
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
        $submission = $this->submissionForStage($id, ['pending_dean'], ['submitted'], 'pending_dean');
        if ($submission === null) {
            return response()->json(['message' => 'No schedule submission is pending Dean approval.'], 422);
        }
        $targetSectionIds = $submission->sections->pluck('id')->map('intval')->values()->all();

        $updated = DB::transaction(function () use ($id, $user, $now, $validated, $submission, $targetSectionIds) {
            $updated = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $targetSectionIds)
                ->where('status', 'submitted')
                ->update([
                    'status' => 'rejected_by_dean',
                    'updated_at' => $now,
                ]);
            $submission->update([
                'status' => 'rejected_by_dean',
                'dean_reviewed_by' => $user->id,
                'dean_reviewed_at' => $now,
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            return $updated;
        });

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_returned_by_dean', $department->id, $this->activeTermId(), [
                'schedules_updated' => $updated,
                'rejection_reason' => $validated['rejection_reason'],
                'selected_section_ids' => $targetSectionIds,
            ], $submission->id);
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
                ['schedules_updated' => $updated, 'schedule_submission_id' => $submission->id],
            );
        }

        return response()->json([
            'message' => 'Department schedule returned by Dean for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submission->id,
        ]);
    }

    public function withdrawSubmission(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['secretary', 'program_head'])) {
            return $forbidden;
        }

        $department = Departments::findOrFail($id);
        $user = $request->user();
        $validated = $request->validate([
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer'],
        ]);
        $sectionIds = array_values(array_unique(array_map('intval', $validated['section_ids'])));
        $allowedSectionIds = $this->departmentSectionIds($id);
        $invalidSectionIds = array_diff($sectionIds, $allowedSectionIds);
        if (! empty($invalidSectionIds)) {
            return response()->json([
                'message' => 'One or more selected sections do not belong to this department.',
            ], 422);
        }

        $query = $this->departmentScheduleQuery($id);
        $withdrawableStatuses = [
            'submitted',
            'approved_by_dean',
            'conditionally_approved',
            'approved',
            'faculty_assignment',
        ];

        // Withdrawal is section-scoped: finalized schedules in other sections
        // must not prevent an eligible selected section from being revised.
        if ((clone $query)
            ->whereIn('section_id', $sectionIds)
            ->where('status', 'finalized')
            ->exists()) {
            return response()->json([
                'message' => 'Finalized schedules cannot be withdrawn. Reopen the finalized workflow first.',
            ], 422);
        }

        $currentStatuses = (clone $query)
            ->whereIn('section_id', $sectionIds)
            ->whereIn('status', $withdrawableStatuses)
            ->pluck('status')
            ->unique()
            ->values();

        if ($currentStatuses->isEmpty()) {
            return response()->json([
                'message' => 'No submitted or VPAA-approved schedule is available to withdraw.',
            ], 422);
        }

        $withdrawableSelectedSectionIds = (clone $query)
            ->whereIn('section_id', $sectionIds)
            ->whereIn('status', $withdrawableStatuses)
            ->distinct()
            ->pluck('section_id')
            ->map(static fn ($sectionId): int => (int) $sectionId)
            ->all();

        if (array_diff($sectionIds, $withdrawableSelectedSectionIds) !== []) {
            return response()->json([
                'message' => 'One or more selected sections are not currently eligible for withdrawal.',
            ], 422);
        }

        $withdrawalStage = $currentStatuses->contains(fn (string $status): bool => in_array($status, ['approved', 'faculty_assignment'], true))
            ? 'vpaa_approved'
            : ($currentStatuses->contains(
                fn (string $status): bool => in_array($status, ['approved_by_dean', 'conditionally_approved'], true)
            ) ? 'vpaa_review' : 'dean_review');
        $legacySubmissionStatus = $withdrawalStage === 'vpaa_approved'
            ? 'approved'
            : ($withdrawalStage === 'vpaa_review' ? 'pending_vpaa' : 'pending_dean');
        $submission = ScheduleSubmission::query()
            ->with('sections')
            ->where('department_id', $id)
            ->where('term_id', $this->activeTermId())
            ->whereIn('status', ['pending_dean', 'pending_vpaa', 'approved'])
            ->whereHas('sections', fn ($sectionQuery) => $sectionQuery->whereIn('sections.id', $sectionIds))
            ->latest('revision_number')
            ->first()
            ?? $this->submissionForStage(
                $id,
                ['pending_dean', 'pending_vpaa', 'approved'],
                $withdrawableStatuses,
                $legacySubmissionStatus,
            );
        if ($submission === null) {
            return response()->json(['message' => 'No approval submission contains the selected sections.'], 422);
        }

        $updated = DB::transaction(function () use ($id, $sectionIds, $withdrawableStatuses, $request, $withdrawalStage, $submission, $user) {
            $completed = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $sectionIds)
                ->whereIn('status', $withdrawableStatuses)
                ->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);

            // Rows about to be unlocked for revision are no longer VPAA-approved,
            // and instructor assignment is only valid after that approval. Leaving
            // faculty_id behind would keep an assignment nobody can see or clear
            // (the assignment workspace and the timetable's phase-2 controls both
            // hide non-approved rows) while it still counted towards teaching load
            // and still fired faculty rules against the very edits the withdrawal
            // was requested for. So the assignment is released here — recorded,
            // not silently dropped — and made again after re-approval.
            $released = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $sectionIds)
                ->where('status', 'completed')
                ->whereNotNull('faculty_id')
                ->get(['id', 'term_id', 'section_id', 'course_id', 'faculty_id']);

            $revision = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $sectionIds)
                ->where('status', 'completed')
                ->update([
                    'status' => 'revision',
                    'faculty_id' => null,
                    'updated_at' => now(),
                ]);

            foreach ($released->groupBy('section_id') as $sectionId => $rows) {
                SchedulingAuditLog::create([
                    'user_id' => $request->user()?->id,
                    'term_id' => (int) $rows->first()->term_id,
                    'section_id' => (int) $sectionId,
                    'department_id' => $id,
                    'action' => 'instructor_assignment_released',
                    'schedule_submission_id' => $submission->id,
                    'metadata' => [
                        'reason' => 'schedule_withdrawn',
                        'withdrawal_stage' => $withdrawalStage,
                        'released_count' => $rows->count(),
                        'schedule_ids' => $rows->pluck('id')->map('intval')->all(),
                        'previous_faculty_ids' => $rows
                            ->mapWithKeys(static fn ($row): array => [
                                (string) $row->id => (int) $row->faculty_id,
                            ])
                            ->all(),
                    ],
                    'created_at' => now(),
                ]);
            }

            $submission->sections()->updateExistingPivot($sectionIds, [
                'state' => 'withdrawn',
                'updated_at' => now(),
            ]);
            $remainingIncluded = DB::table('schedule_submission_sections')
                ->where('schedule_submission_id', $submission->id)
                ->where('state', 'included')
                ->exists();
            $submission->update([
                'status' => $remainingIncluded ? 'partially_withdrawn' : 'withdrawn',
                'withdrawn_by' => $user->id,
                'withdrawn_at' => now(),
            ]);

            return [
                'completed' => $completed,
                'revision' => $revision,
                'instructors_released' => $released->count(),
            ];
        });

        // The assignment workspace caches its payload for five minutes and the
        // withdrawn rows just left the statuses it lists, so serving the stale
        // copy would still show instructors that were released above.
        if ($updated['instructors_released'] > 0) {
            ApiCache::forgetGroup('instructor_assignments.index');
        }

        $term = Terms::query()->find($this->activeTermId());
        if ($updated['revision'] > 0) {
            $this->recordWorkflowAudit($request, 'schedule_withdrawn', $department->id, $term?->id, [
                'schedules_updated' => $updated['revision'],
                'sections_unlocked' => count($sectionIds),
                'withdrawal_stage' => $withdrawalStage,
                'instructors_released' => $updated['instructors_released'],
                'selected_section_ids' => $sectionIds,
            ], $submission->id);
        }
        $this->notifications->notifyRoles(
            ['vpaa', 'dean', 'secretary', 'program_head'],
            'schedule_withdrawn',
            'Schedule submission withdrawn',
            $this->notifications->departmentWorkflowMessage(
                'withdrew',
                $department,
                $term,
                $user,
                $updated['revision'],
            ),
            $user,
            $department->id,
            $term?->id,
            null,
            [
                'schedules_updated' => $updated['revision'],
                'sections_unlocked' => count($sectionIds),
                'selected_section_ids' => $sectionIds,
                'withdrawal_stage' => $withdrawalStage,
                'instructors_released' => $updated['instructors_released'],
                'schedule_submission_id' => $submission->id,
            ],
        );

        return response()->json([
            'message' => 'Selected section schedules withdrawn for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated['revision'],
            'sections_unlocked' => count($sectionIds),
            'withdrawal_stage' => $withdrawalStage,
            'instructors_released' => $updated['instructors_released'],
            'schedule_submission_id' => $submission->id,
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
        $submission = $this->submissionForStage(
            $id,
            ['pending_vpaa'],
            ['approved_by_dean', 'conditionally_approved'],
            'pending_vpaa',
        );
        if ($submission === null) {
            return response()->json(['message' => 'No schedule submission is pending VPAA approval.'], 422);
        }
        $targetSectionIds = $submission->sections->pluck('id')->map('intval')->values()->all();

        $updated = DB::transaction(function () use ($id, $user, $now, $submission, $targetSectionIds) {
            $updated = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $targetSectionIds)
                ->whereIn('status', ['approved_by_dean', 'conditionally_approved'])
                ->update([
                    'status' => 'faculty_assignment',
                    'updated_at' => $now,
                ]);
            $submission->update([
                'status' => 'approved',
                'vpaa_reviewed_by' => $user->id,
                'vpaa_reviewed_at' => $now,
                'rejection_reason' => null,
            ]);

            return $updated;
        });

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_approved_by_vpaa', $department->id, $this->activeTermId(), [
                'schedules_updated' => $updated,
                'selected_section_ids' => $targetSectionIds,
            ], $submission->id);
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
                ['schedules_updated' => $updated, 'schedule_submission_id' => $submission->id],
            );
        }

        return response()->json([
            'message' => 'Department schedule approved by VPAA.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submission->id,
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
        $submission = $this->submissionForStage(
            $id,
            ['pending_vpaa'],
            ['approved_by_dean', 'conditionally_approved'],
            'pending_vpaa',
        );
        if ($submission === null) {
            return response()->json(['message' => 'No schedule submission is pending VPAA approval.'], 422);
        }
        $targetSectionIds = $submission->sections->pluck('id')->map('intval')->values()->all();

        $updated = DB::transaction(function () use ($id, $user, $now, $validated, $submission, $targetSectionIds) {
            $updated = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $targetSectionIds)
                ->whereIn('status', ['approved_by_dean', 'conditionally_approved'])
                ->update([
                    'status' => 'rejected',
                    'updated_at' => $now,
                ]);
            $submission->update([
                'status' => 'rejected_by_vpaa',
                'vpaa_reviewed_by' => $user->id,
                'vpaa_reviewed_at' => $now,
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            return $updated;
        });

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_returned_by_vpaa', $department->id, $this->activeTermId(), [
                'schedules_updated' => $updated,
                'rejection_reason' => $validated['rejection_reason'],
                'selected_section_ids' => $targetSectionIds,
            ], $submission->id);
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
                ['schedules_updated' => $updated, 'schedule_submission_id' => $submission->id],
            );
        }

        return response()->json([
            'message' => 'Department schedule returned by VPAA for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submission->id,
        ]);
    }

    private function recordWorkflowAudit(
        Request $request,
        string $action,
        int $departmentId,
        ?int $termId,
        array $metadata = [],
        ?int $submissionId = null,
    ): void {
        $historyGroupId = (string) Str::uuid();
        $metadata['history_group_id'] = $historyGroupId;
        $schedules = collect();

        // Workflow transitions use bulk updates and therefore do not fire
        // Schedule model events. Capture the resulting rows explicitly so
        // approvals, returns, submissions, and withdrawals are visible in
        // schedule history as well as the activity log.
        if ($termId !== null) {
            $targetSectionIds = collect($metadata['selected_section_ids'] ?? [])
                ->map('intval')
                ->filter()
                ->unique()
                ->values();
            $departmentSchedules = Schedule::query()
                ->where('department_id', $departmentId)
                ->where('term_id', $termId)
                ->get();
            $allSectionIds = $departmentSchedules->pluck('section_id')->filter()->unique();
            $schedules = $targetSectionIds->isNotEmpty()
                ? $departmentSchedules->whereIn('section_id', $targetSectionIds)->values()
                : $departmentSchedules;
            $sectionIds = $schedules->pluck('section_id')->filter()->unique()->values()->all();
            $metadata['affected_section_ids'] = $sectionIds;
            $metadata['affected_section_count'] = count($sectionIds);
            $metadata['entire_schedule'] = count($sectionIds) > 0 && count($sectionIds) === $allSectionIds->count();
            $metadata['history_scope'] = $metadata['entire_schedule']
                ? 'entire_schedule'
                : (count($sectionIds) >= 2 ? 'multiple_sections' : 'section');
            $metadata['history_group_id'] = $historyGroupId;
        }

        $version = null;
        if ($schedules->isNotEmpty()) {
            $version = $this->historyRecorder->record(
                $action,
                [],
                $schedules,
                $request->user()?->id,
                $termId,
                $departmentId,
                'department_workflow',
                null,
                $metadata,
            );
        }

        SchedulingAuditLog::create([
            'user_id' => $request->user()?->id,
            'term_id' => $termId,
            'department_id' => $departmentId,
            'action' => $action,
            'history_version_id' => $version?->id,
            'schedule_submission_id' => $submissionId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

    }
}
