<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Department\DepartmentScheduleStatusDeriver;
use App\Services\Scheduling\Department\ScheduleOverviewService;
use App\Services\Scheduling\Support\SchedulingPolicy;
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
        private readonly DepartmentScheduleStatusDeriver $statusDeriver,
        private readonly ScheduleOverviewService $scheduleOverviews,
    ) {}

    private function activeSemesterId(): ?int
    {
        return Semester::where('is_active', true)->value('id');
    }

    private function departmentSectionIds(int $departmentId): array
    {
        $query = Sections::where('department_id', $departmentId)
            ->where('status', 'active');

        $activeSemesterId = $this->activeSemesterId();
        if ($activeSemesterId) {
            $query->where('semester_id', $activeSemesterId);
        }

        return $query->pluck('id')->toArray();
    }

    private function departmentScheduleQuery(int $departmentId)
    {
        $query = Schedule::whereIn('section_id', $this->departmentSectionIds($departmentId));

        $activeSemesterId = $this->activeSemesterId();
        if ($activeSemesterId) {
            $query->where('semester_id', $activeSemesterId);
        }

        return $query;
    }

    private function submissionForStage(
        int $departmentId,
        array $submissionStatuses,
        array $scheduleStatuses,
        string $legacyStatus,
    ): ?ScheduleSubmission {
        $semesterId = $this->activeSemesterId();
        if ($semesterId === null) {
            return null;
        }

        $submission = ScheduleSubmission::query()
            ->with('sections')
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
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

        return DB::transaction(function () use ($departmentId, $semesterId, $legacyStatus, $sectionIds): ScheduleSubmission {
            $revisionNumber = ((int) ScheduleSubmission::query()
                ->where('department_id', $departmentId)
                ->where('semester_id', $semesterId)
                ->lockForUpdate()
                ->max('revision_number')) + 1;
            $submission = ScheduleSubmission::create([
                'department_id' => $departmentId,
                'semester_id' => $semesterId,
                'revision_number' => $revisionNumber,
                'status' => $legacyStatus,
                'submitted_at' => now(),
            ]);
            $submission->sections()->attach($sectionIds->all(), ['state' => 'included']);

            return $submission->load('sections');
        });
    }

    /**
     * Drop the cached reads a workflow transition invalidates.
     *
     * Every approval screen is built from `/initial-data`, which caches its
     * encoded payload for five minutes. Without this, a Dean approval returned
     * "approved" while the very next load of the queue replayed the cached
     * payload -- the submission still `pending_dean`, its meetings still
     * `submitted` -- so an approved schedule sat in the Pending tab until the
     * entry expired.
     *
     * Only the two collections a transition actually rewrites are bumped;
     * `rooms`/`courses`/`users` keep their cached payloads.
     *
     * @param  bool  $affectsAssignments  True when the transition moves meetings
     *                                    into or out of the instructor-assignable
     *                                    statuses, which the assignment
     *                                    workspace caches separately.
     */
    private function forgetWorkflowCaches(bool $affectsAssignments = false): void
    {
        ApiCache::forgetGroups([
            'initial.data.schedules',
            'initial.data.schedule_submissions',
            ...($affectsAssignments ? ['instructor_assignments.index'] : []),
        ]);
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
        return $this->statusDeriver->derive($scheduleStatuses);
    }

    /**
     * GET /api/departments/{id}/schedule-status
     *
     * Returns every section in the department together with its derived
     * schedule status, grouped so the frontend can build the 4-stage counts
     * and per-year-level checklist without extra round-trips.
     */
    /**
     * Submitting sends the department's schedules to its Dean for approval, so
     * there has to be one. Without this the submission succeeds, the schedules
     * move to "submitted", and they sit there with nobody able to act on them --
     * a dead end that can only be undone by withdrawing.
     *
     * A Dean is a user account, not a column: role "dean", assigned to this
     * department, active, and not soft-deleted.
     */
    private function departmentHasDean(int $departmentId): bool
    {
        return User::query()
            ->where('role', 'dean')
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->exists();
    }

    public function scheduleStatus(int $id): JsonResponse
    {
        $department = Departments::findOrFail($id);
        $activeSemesterId = $this->activeSemesterId();

        $sections = Sections::with(['schedules' => function ($query) use ($activeSemesterId) {
            $query->select('id', 'section_id', 'status')
                ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId));
        }])
            ->where('department_id', $id)
            ->where('status', 'active')
            ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId))
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
            // Lets the UI disable Submit and explain why, instead of letting the
            // request fail. The backend still enforces it on submit.
            'has_dean' => $this->departmentHasDean((int) $department->id),
            // Delegated work the dashboard cannot see: these classes sit in other
            // departments' sections, so they are absent from the schedule rows the
            // dashboard loads for its own department.
            'cross_department_pending' => $this->crossDepartmentPendingCount((int) $department->id, $activeSemesterId),
        ]);
    }

    /**
     * Classes another department owns that this one has to staff, still without
     * an instructor.
     *
     * Counted over distinct section + course pairs rather than schedule rows: a
     * row is one meeting, so an MWF class would otherwise read as three items of
     * outstanding work. A class counts as pending while any of its meetings is
     * unassigned.
     */
    private function crossDepartmentPendingCount(int $departmentId, ?int $activeSemesterId): int
    {
        if ($activeSemesterId === null) {
            return 0;
        }

        return Schedule::query()
            ->where('semester_id', $activeSemesterId)
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES)
            ->whereNull('faculty_id')
            ->where('department_id', '!=', $departmentId)
            ->whereHas('course', fn ($course) => $course
                ->where('status', 'active')
                ->where('teaching_department_id', $departmentId))
            ->distinct()
            ->get(['section_id', 'course_id'])
            ->count();
    }

    /**
     * GET /api/departments/schedule-overview
     *
     * Every department's schedule rolled up for the All Schedules screen, with
     * its sections nested so the drill-down needs no second request.
     *
     * This exists because the screen used to build the same numbers by counting
     * the schedule rows `/initial-data` happened to return, which is capped.
     * The counts here are aggregated in SQL over the whole active semester.
     *
     * Only a VPAA sees the institution. Everyone else is scoped to the
     * department they are assigned to, and an unassigned account sees nothing
     * rather than everything.
     *
     * The VPAA's own counts cover approved meetings only: work still in a
     * department's hands, or awaiting VPAA action, is not part of the portal's
     * timetable. Pending submissions are reviewed on the Schedule Approval
     * screen instead.
     */
    public function scheduleOverview(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'vpaa') {
            return response()->json($this->scheduleOverviews->overview(
                null,
                SchedulingPolicy::VPAA_VISIBLE_STATUSES,
            ));
        }

        $departmentId = $user->department_id !== null ? (int) $user->department_id : null;
        if ($departmentId === null) {
            return response()->json([
                'message' => 'Your account is not assigned to a department.',
            ], 403);
        }

        return response()->json($this->scheduleOverviews->overview($departmentId));
    }

    /**
     * POST /api/departments/{id}/submit-schedules
     *
     * Initial submission requires every active section to be ready. After a
     * partial withdrawal, only the completed revision cohort is submitted;
     * finalized and already-approved cohorts remain at their current stage.
     *
     * Capability middleware decides who may submit. Organizational assignment
     * decides which department and, for Program Heads, which program is in scope.
     */
    public function submitSchedules(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'vpaa' && (int) $user->department_id !== $id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->role === 'program_head' && $user->program_id === null) {
            return response()->json(['message' => 'Program Head accounts must be assigned to a program.'], 403);
        }

        if (! Program::query()->where('department_id', $id)->exists()) {
            return response()->json(['message' => 'Create at least one Program under this Department before scheduling.'], 422);
        }

        if (! $this->departmentHasDean($id)) {
            return response()->json([
                'message' => 'Submission unavailable. Please assign a Department Dean before submitting the schedule.',
                'error_code' => 'department_dean_missing',
            ], 422);
        }

        $department = Departments::findOrFail($id);
        $activeSemesterId = $this->activeSemesterId();
        $validated = $request->validate([
            'section_ids' => ['nullable', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct'],
        ]);

        $sections = Sections::with(['schedules' => function ($query) use ($activeSemesterId) {
            $query->select('id', 'section_id', 'status')
                ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId));
        }])
            ->where('department_id', $id)
            ->when($user->role === 'program_head', fn ($query) => $query->where('program_id', $user->program_id))
            ->where('status', 'active')
            ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId))
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
            'reassignment',
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
                'message' => 'Cannot submit while recalled sections are still under revision.',
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

        $result = DB::transaction(function () use ($sectionIds, $request, $department, $activeSemesterId, $user): array {
            $parentSubmission = ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('semester_id', $activeSemesterId)
                ->whereIn('status', ['withdrawn', 'partially_withdrawn', 'rejected_by_dean', 'rejected_by_vpaa'])
                ->whereHas('sections', fn ($query) => $query->whereIn('sections.id', $sectionIds))
                ->latest('revision_number')
                ->first();
            $revisionNumber = ((int) ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('semester_id', $activeSemesterId)
                ->lockForUpdate()
                ->max('revision_number')) + 1;
            $submission = ScheduleSubmission::create([
                'department_id' => $department->id,
                'semester_id' => $activeSemesterId,
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
                $this->recordWorkflowAudit($request, 'schedule_submitted', $department->id, $activeSemesterId, [
                    'schedules_updated' => $updated,
                    'selected_section_ids' => $sectionIds,
                ], $submission->id);
            }

            return compact('updated', 'submission');
        });
        $updated = $result['updated'];
        $submission = $result['submission'];
        $this->forgetWorkflowCaches();

        if ($updated > 0) {
            $semester = Semester::query()->find($this->activeSemesterId());
            $this->notifications->notifyRoles(
                ['dean', 'secretary', 'program_head'],
                'schedule_submitted',
                'Schedule submitted for Dean review',
                $this->notifications->departmentWorkflowMessage(
                    'submitted',
                    $department,
                    $semester,
                    $user,
                    $updated,
                ),
                $user,
                $department->id,
                $semester?->id,
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
        $this->forgetWorkflowCaches();

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_approved_by_dean', $department->id, $this->activeSemesterId(), [
                'schedules_updated' => $updated,
                'selected_section_ids' => $targetSectionIds,
                'approval_override' => $override,
                'approval_override_reason' => $override ? ($validated['override_reason'] ?? null) : null,
            ], $submission->id);
            $semester = Semester::query()->find($this->activeSemesterId());
            $this->notifications->notifyRoles(
                ['vpaa', 'dean', 'secretary', 'program_head'],
                'schedule_approved_by_dean',
                'Dean approved department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'approved and forwarded',
                    $department,
                    $semester,
                    $user,
                    $updated,
                ),
                $user,
                $department->id,
                $semester?->id,
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
        $this->forgetWorkflowCaches();

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_returned_by_dean', $department->id, $this->activeSemesterId(), [
                'schedules_updated' => $updated,
                'rejection_reason' => $validated['rejection_reason'],
                'selected_section_ids' => $targetSectionIds,
            ], $submission->id);
            $semester = Semester::query()->find($this->activeSemesterId());
            $this->notifications->notifyRoles(
                ['dean', 'secretary', 'program_head'],
                'schedule_returned_by_dean',
                'Dean returned department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'returned',
                    $department,
                    $semester,
                    $user,
                    $updated,
                    $validated['rejection_reason'],
                ),
                $user,
                $department->id,
                $semester?->id,
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
        $department = Departments::findOrFail($id);
        $user = $request->user();
        if ($user->role !== 'vpaa' && (int) $user->department_id !== $id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($user->role === 'program_head' && $user->program_id === null) {
            return response()->json(['message' => 'Program Head accounts must be assigned to a program.'], 403);
        }
        $validated = $request->validate([
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer'],
        ]);
        $sectionIds = array_values(array_unique(array_map('intval', $validated['section_ids'])));
        $allowedSectionIds = $this->departmentSectionIds($id);
        if ($user->role === 'program_head') {
            $allowedSectionIds = Sections::query()
                ->whereIn('id', $allowedSectionIds)
                ->where('program_id', $user->program_id)
                ->pluck('id')
                ->map('intval')
                ->all();
        }
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
            'reassignment',
        ];

        // Withdrawal is section-scoped: finalized schedules in other sections
        // must not prevent an eligible selected section from being revised.
        if ((clone $query)
            ->whereIn('section_id', $sectionIds)
            ->where('status', 'finalized')
            ->exists()) {
            return response()->json([
                'message' => 'Finalized schedules cannot be recalled. Use Reassignment to reopen them first.',
            ], 422);
        }

        // A section under Reassignment is recalled like any other approved
        // section. Recalling releases every instructor on it (below), so it no
        // longer has to be cleared first -- which a department could not do
        // anyway for a delegated course whose instructor another college chose.

        $currentStatuses = (clone $query)
            ->whereIn('section_id', $sectionIds)
            ->whereIn('status', $withdrawableStatuses)
            ->pluck('status')
            ->unique()
            ->values();

        if ($currentStatuses->isEmpty()) {
            return response()->json([
                'message' => 'No submitted or VPAA-approved schedule is available to recall.',
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
                'message' => 'One or more selected sections cannot be recalled right now.',
            ], 422);
        }

        $withdrawalStage = $currentStatuses->contains(fn (string $status): bool => in_array($status, ['approved', 'faculty_assignment', 'reassignment'], true))
            ? 'vpaa_approved'
            : ($currentStatuses->contains(
                fn (string $status): bool => in_array($status, ['approved_by_dean', 'conditionally_approved'], true)
            ) ? 'vpaa_review' : 'dean_review');
        $legacySubmissionStatus = $withdrawalStage === 'vpaa_approved'
            ? 'approved'
            : ($withdrawalStage === 'vpaa_review' ? 'pending_vpaa' : 'pending_dean');
        $submissions = ScheduleSubmission::query()
            ->with('sections')
            ->where('department_id', $id)
            ->where('semester_id', $this->activeSemesterId())
            ->whereIn('status', ['pending_dean', 'pending_vpaa', 'approved', 'partially_withdrawn'])
            ->whereHas('sections', fn ($sectionQuery) => $sectionQuery
                ->whereIn('sections.id', $sectionIds)
                ->where('schedule_submission_sections.state', 'included'))
            ->orderByDesc('revision_number')
            ->get();
        $submissionBySectionId = collect();
        foreach ($submissions as $candidate) {
            foreach ($candidate->sections as $candidateSection) {
                $candidateSectionId = (int) $candidateSection->id;
                if (in_array($candidateSectionId, $sectionIds, true)
                    && $candidateSection->pivot->state === 'included'
                    && ! $submissionBySectionId->has($candidateSectionId)) {
                    $submissionBySectionId->put($candidateSectionId, $candidate);
                }
            }
        }

        $missingSubmissionSectionIds = array_values(array_diff($sectionIds, $submissionBySectionId->keys()->all()));
        if ($missingSubmissionSectionIds !== []) {
            $legacySubmission = $this->submissionForStage(
                $id,
                ['pending_dean', 'pending_vpaa', 'approved', 'partially_withdrawn'],
                $withdrawableStatuses,
                $legacySubmissionStatus,
            );
            if ($legacySubmission !== null) {
                foreach ($legacySubmission->sections as $legacySection) {
                    $legacySectionId = (int) $legacySection->id;
                    if (in_array($legacySectionId, $missingSubmissionSectionIds, true)
                        && $legacySection->pivot->state === 'included') {
                        $submissionBySectionId->put($legacySectionId, $legacySubmission);
                    }
                }
            }
        }

        if (array_diff($sectionIds, $submissionBySectionId->keys()->all()) !== []) {
            return response()->json(['message' => 'No approval submission contains the selected sections.'], 422);
        }

        $submissionSections = $submissionBySectionId
            ->groupBy(static fn (ScheduleSubmission $submission): int => (int) $submission->id, true)
            ->map(static fn ($group) => $group->keys()->map('intval')->values()->all());
        $affectedSubmissions = $submissionBySectionId
            ->values()
            ->unique('id')
            ->sortByDesc('revision_number')
            ->values();
        $primarySubmission = $affectedSubmissions->first();

        $semesterId = $this->activeSemesterId();
        $updated = DB::transaction(function () use ($id, $sectionIds, $withdrawableStatuses, $affectedSubmissions, $submissionSections, $user, $semesterId) {
            // A withdrawn section's instructors are released. Its rows leave the
            // assignment statuses -- off every assignment screen and out of the
            // instructor's load -- but the faculty conflict rule counts any row
            // that carries an instructor, so a kept instructor went on blocking
            // that person from every other class at the same hour, through a
            // class nobody could see or clear. The previous instructor of each
            // meeting is recorded below, so the release can be traced.
            $released = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $sectionIds)
                ->whereIn('status', $withdrawableStatuses)
                ->whereNotNull('faculty_id')
                ->get(['id', 'section_id', 'faculty_id']);

            if ($released->isNotEmpty()) {
                Schedule::query()
                    ->whereIn('id', $released->pluck('id'))
                    ->update([
                        'faculty_id' => null,
                        'faculty_conflict_override' => false,
                        'updated_at' => now(),
                    ]);
            }

            // Instructor assignment starts over once the revision is approved, so
            // no recalled row may keep a "done" handoff from the last round.
            $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $sectionIds)
                ->whereIn('status', $withdrawableStatuses)
                ->where('faculty_assignment_done', true)
                ->update(['faculty_assignment_done' => false, 'updated_at' => now()]);

            $completed = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $sectionIds)
                ->whereIn('status', $withdrawableStatuses)
                ->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);

            $revision = $this->departmentScheduleQuery($id)
                ->whereIn('section_id', $sectionIds)
                ->where('status', 'completed')
                ->update([
                    'status' => 'revision',
                    'updated_at' => now(),
                ]);

            foreach ($affectedSubmissions as $submission) {
                $submissionSectionIds = $submissionSections->get((int) $submission->id, []);
                $submission->sections()->updateExistingPivot($submissionSectionIds, [
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
            }

            foreach ($released->groupBy('section_id') as $sectionId => $rows) {
                SchedulingAuditLog::create([
                    'user_id' => $user->id,
                    'semester_id' => $semesterId,
                    'section_id' => (int) $sectionId,
                    'department_id' => $id,
                    'action' => 'instructor_assignment_released',
                    'metadata' => [
                        'reason' => 'schedule_withdrawn',
                        'released_count' => $rows->count(),
                        'schedule_ids' => $rows->pluck('id')->map('intval')->values()->all(),
                        'previous_faculty_ids' => $rows->mapWithKeys(
                            static fn (Schedule $row): array => [(string) $row->id => (int) $row->faculty_id]
                        )->all(),
                        'faculty_ids' => $rows->pluck('faculty_id')->map('intval')->unique()->values()->all(),
                    ],
                    'created_at' => now(),
                ]);
            }

            return [
                'completed' => $completed,
                'revision' => $revision,
                'instructors_released' => $released->count(),
                'submission_ids' => $affectedSubmissions->pluck('id')->map('intval')->values()->all(),
            ];
        });

        // The assignment workspace and the faculty loads cache their payloads;
        // withdrawn rows leave the assignment statuses and lose their instructors.
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        $semester = Semester::query()->find($this->activeSemesterId());
        if ($updated['revision'] > 0) {
            $this->recordWorkflowAudit($request, 'schedule_withdrawn', $department->id, $semester?->id, [
                'schedules_updated' => $updated['revision'],
                'sections_unlocked' => count($sectionIds),
                'withdrawal_stage' => $withdrawalStage,
                'instructors_released' => $updated['instructors_released'],
                'selected_section_ids' => $sectionIds,
                'schedule_submission_ids' => $updated['submission_ids'],
                'submission_section_ids' => $submissionSections->all(),
            ], $primarySubmission?->id);
        }
        $this->notifications->notifyRoles(
            ['vpaa', 'dean', 'secretary', 'program_head'],
            'schedule_withdrawn',
            'Schedule submission recalled',
            $this->notifications->departmentWorkflowMessage(
                'recalled',
                $department,
                $semester,
                $user,
                $updated['revision'],
            ),
            $user,
            $department->id,
            $semester?->id,
            null,
            [
                'schedules_updated' => $updated['revision'],
                'sections_unlocked' => count($sectionIds),
                'selected_section_ids' => $sectionIds,
                'withdrawal_stage' => $withdrawalStage,
                'instructors_released' => $updated['instructors_released'],
                'schedule_submission_id' => $primarySubmission?->id,
                'schedule_submission_ids' => $updated['submission_ids'],
            ],
        );

        return response()->json([
            'message' => 'Selected section schedules recalled for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated['revision'],
            'sections_unlocked' => count($sectionIds),
            'withdrawal_stage' => $withdrawalStage,
            'instructors_released' => $updated['instructors_released'],
            'schedule_submission_id' => $primarySubmission?->id,
            'schedule_submission_ids' => $updated['submission_ids'],
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
        // VPAA approval is what moves meetings into `faculty_assignment`, the
        // first instructor-assignable status, so the assignment workspace's own
        // cached payload has to go with it.
        $this->forgetWorkflowCaches(affectsAssignments: true);

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_approved_by_vpaa', $department->id, $this->activeSemesterId(), [
                'schedules_updated' => $updated,
                'selected_section_ids' => $targetSectionIds,
            ], $submission->id);
            $semester = Semester::query()->find($this->activeSemesterId());
            $this->notifications->notifyRoles(
                ['vpaa', 'dean', 'secretary', 'program_head'],
                'schedule_approved_by_vpaa',
                'VPAA approved department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'approved',
                    $department,
                    $semester,
                    $user,
                    $updated,
                ),
                $user,
                $department->id,
                $semester?->id,
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
        $this->forgetWorkflowCaches(affectsAssignments: true);

        if ($updated > 0) {
            $this->recordWorkflowAudit($request, 'schedule_returned_by_vpaa', $department->id, $this->activeSemesterId(), [
                'schedules_updated' => $updated,
                'rejection_reason' => $validated['rejection_reason'],
                'selected_section_ids' => $targetSectionIds,
            ], $submission->id);
            $semester = Semester::query()->find($this->activeSemesterId());
            $this->notifications->notifyRoles(
                ['vpaa', 'dean', 'secretary', 'program_head'],
                'schedule_returned_by_vpaa',
                'VPAA returned department schedule',
                $this->notifications->departmentWorkflowMessage(
                    'returned',
                    $department,
                    $semester,
                    $user,
                    $updated,
                    $validated['rejection_reason'],
                ),
                $user,
                $department->id,
                $semester?->id,
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
        ?int $semesterId,
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
        if ($semesterId !== null) {
            $targetSectionIds = collect($metadata['selected_section_ids'] ?? [])
                ->map('intval')
                ->filter()
                ->unique()
                ->values();
            $departmentSchedules = Schedule::query()
                ->where('department_id', $departmentId)
                ->where('semester_id', $semesterId)
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
                $semesterId,
                $departmentId,
                'department_workflow',
                null,
                $metadata,
            );
        }

        SchedulingAuditLog::create([
            'user_id' => $request->user()?->id,
            'semester_id' => $semesterId,
            'department_id' => $departmentId,
            'action' => $action,
            'history_version_id' => $version?->id,
            'schedule_submission_id' => $submissionId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

    }
}
