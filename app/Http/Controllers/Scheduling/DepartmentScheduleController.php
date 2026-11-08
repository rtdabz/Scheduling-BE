<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Department\CrossDepartmentApprovalGate;
use App\Services\Scheduling\Department\DepartmentScheduleStatusDeriver;
use App\Services\Scheduling\Department\ScheduleOverviewService;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Schedule\ScheduleConflictCase;
use App\Services\Scheduling\Schedule\ScheduleConflictScanner;
use App\Services\Scheduling\ScheduleSignatories;
use App\Services\Scheduling\Submission\RevisionChangeRecorder;
use App\Services\Scheduling\Submission\ScheduleDescriptors;
use App\Services\Scheduling\Submission\SubmissionStatusResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DepartmentScheduleController extends Controller
{
    private const SUBMITTABLE_STATUSES = ['draft', 'revision', 'completed', 'rejected', 'rejected_by_dean'];

    private const WITHDRAWABLE_STATUSES = ['submitted', 'approved_by_dean', 'conditionally_approved', 'approved', 'faculty_assignment', 'reassignment'];

    public function __construct(
        private readonly SystemNotificationService $notifications,
        private readonly ScheduleHistoryRecorder $historyRecorder,
        private readonly ScheduleDescriptors $descriptors,
        private readonly DepartmentScheduleStatusDeriver $statusDeriver,
        private readonly ScheduleOverviewService $scheduleOverviews,
        private readonly ScheduleConflictScanner $conflictScanner,
        private readonly ScheduleAuthorizationService $authorization,
        private readonly CrossDepartmentApprovalGate $crossDepartmentGate,
        private readonly ScheduleSignatories $signatories,
    ) {}

    /**
     * @param  list<int>  $sectionIds
     * @return list<ScheduleConflictCase>
     */
    private function openConflictsIn(int $departmentId, array $sectionIds, ?int $semesterId = null): array
    {
        $semesterId = (int) ($semesterId ?? $this->activeSemesterId());
        $sections = array_flip(array_map('intval', $sectionIds));

        return array_values(array_filter(
            $this->conflictScanner->scan($semesterId, $departmentId),
            static function (ScheduleConflictCase $case) use ($departmentId, $sections): bool {
                foreach ([$case->schedule, $case->otherSchedule] as $row) {
                    if ((int) ($row['department_id'] ?? 0) === $departmentId && isset($sections[(int) ($row['section_id'] ?? 0)])) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @param  list<ScheduleConflictCase>  $conflicts
     */
    private function openConflictsResponse(array $conflicts): JsonResponse
    {
        $count = count($conflicts);

        return response()->json([
            'message' => "This schedule has {$count} open conflict".($count === 1 ? '' : 's')
                .'. Return it for revision so the department can resolve '.($count === 1 ? 'it' : 'them').'.',
            'error_code' => 'open_conflicts',
            'conflicts' => array_map(static fn (ScheduleConflictCase $case): array => $case->toArray(), $conflicts),
        ], 422);
    }

    private function activeSemesterId(): ?int
    {
        return Semester::where('is_active', true)->value('id');
    }

    /** Active sections of the department in $semesterId (every semester when null). */
    private function sectionIdsFor(int $departmentId, ?int $semesterId): array
    {
        return Sections::where('department_id', $departmentId)
            ->where('status', 'active')
            ->when($semesterId, fn ($query) => $query->where('semester_id', $semesterId))
            ->pluck('id')
            ->toArray();
    }

    /** Meetings of the department's active sections in $semesterId (every semester when null). */
    private function scheduleQueryFor(int $departmentId, ?int $semesterId)
    {
        return Schedule::whereIn('section_id', $this->sectionIdsFor($departmentId, $semesterId))
            ->when($semesterId, fn ($query) => $query->where('semester_id', $semesterId));
    }

    /**
     * Serializes the department's approval workflow: submission, recall, and the Dean and VPAA
     * decisions. It must be the first statement of each workflow transaction. A MySQL/MariaDB
     * repeatable-read snapshot starts at the transaction's first plain read, so taking this lock
     * first means every later read observes whatever the previous holder committed. The department
     * row exists before any submission does, so it also guards revision-number allocation.
     */
    private function lockDepartmentWorkflow(int $departmentId): void
    {
        Departments::query()->whereKey($departmentId)->lockForUpdate()->value('id');
    }

    /**
     * Included sections of a submission, read from the database (never a cached relation).
     *
     * @return list<int>
     */
    private function currentIncludedSectionIds(int $submissionId): array
    {
        return DB::table('schedule_submission_sections')
            ->where('schedule_submission_id', $submissionId)
            ->where('state', 'included')
            ->orderBy('section_id')
            ->pluck('section_id')
            ->map('intval')
            ->all();
    }

    /**
     * Sections whose meetings predate submission records: at the stage, and not included in any
     * open submission (those are reviewed through their own submission).
     *
     * @return list<int>
     */
    private function legacyCandidateSectionIds(int $departmentId, int $semesterId, array $scheduleStatuses): array
    {
        $ownedSectionIds = DB::table('schedule_submission_sections')
            ->join('schedule_submissions', 'schedule_submissions.id', '=', 'schedule_submission_sections.schedule_submission_id')
            ->where('schedule_submissions.department_id', $departmentId)
            ->where('schedule_submissions.semester_id', $semesterId)
            ->whereIn('schedule_submissions.status', ['pending_dean', 'pending_vpaa', 'approved', 'partially_withdrawn'])
            ->where('schedule_submission_sections.state', 'included')
            ->pluck('schedule_submission_sections.section_id')
            ->map('intval')
            ->all();

        return $this->scheduleQueryFor($departmentId, $semesterId)
            ->whereIn('status', $scheduleStatuses)
            ->whereNotIn('section_id', $ownedSectionIds)
            ->distinct()
            ->orderBy('section_id')
            ->pluck('section_id')
            ->map('intval')
            ->values()
            ->all();
    }

    /**
     * Read-only preflight: which submission (by ID) and which sections this review targets. The
     * result is an expectation re-checked under the workflow lock, never an update target. A
     * request without a submission ID is pinned here to the revision it resolves to, so a newer
     * revision created while it waits is never substituted. A null submission_id means legacy
     * meetings with no submission yet; the submission is created under the lock.
     *
     * @return array{submission_id: ?int, section_ids: list<int>}|null
     */
    private function reviewTarget(
        int $departmentId,
        ?int $semesterId,
        array $submissionStatuses,
        array $scheduleStatuses,
        ?int $submissionId,
    ): ?array {
        if ($semesterId === null) {
            return null;
        }

        $submission = ScheduleSubmission::query()
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->when(
                $submissionId !== null,
                fn ($query) => $query->whereKey($submissionId)->whereIn('status', [...$submissionStatuses, 'partially_withdrawn']),
                fn ($query) => $query->whereIn('status', $submissionStatuses)->latest('revision_number'),
            )
            ->first(['id']);

        if ($submission !== null) {
            $sectionIds = $this->currentIncludedSectionIds((int) $submission->id);
        } elseif ($submissionId === null) {
            $sectionIds = $this->legacyCandidateSectionIds($departmentId, $semesterId, $scheduleStatuses);
        } else {
            return null;
        }

        $atStage = $sectionIds !== [] && $this->scheduleQueryFor($departmentId, $semesterId)
            ->whereIn('section_id', $sectionIds)
            ->whereIn('status', $scheduleStatuses)
            ->exists();

        return $atStage ? ['submission_id' => $submission?->id, 'section_ids' => $sectionIds] : null;
    }

    /**
     * Under the workflow lock: reload the pinned submission, its included sections, and their
     * meetings, and confirm they still match the preflight target at this stage. Any difference --
     * a recall, a resubmission, a competing decision -- is a stale review.
     *
     * @param  array{submission_id: ?int, section_ids: list<int>}  $target
     * @return array{submission: ?ScheduleSubmission, section_ids: list<int>, meetings: Collection}|null
     */
    private function lockedReviewScope(
        int $departmentId,
        int $semesterId,
        array $target,
        array $submissionStatuses,
        array $scheduleStatuses,
    ): ?array {
        $submission = null;
        if ($target['submission_id'] === null) {
            $claimed = ScheduleSubmission::query()
                ->where('department_id', $departmentId)
                ->where('semester_id', $semesterId)
                ->whereIn('status', $submissionStatuses)
                ->exists();
            $sectionIds = $claimed ? [] : $this->legacyCandidateSectionIds($departmentId, $semesterId, $scheduleStatuses);
        } else {
            $submission = ScheduleSubmission::query()
                ->whereKey($target['submission_id'])
                ->where('department_id', $departmentId)
                ->where('semester_id', $semesterId)
                ->lockForUpdate()
                ->first();
            $sectionIds = $submission !== null && in_array($submission->status, [...$submissionStatuses, 'partially_withdrawn'], true)
                ? $this->currentIncludedSectionIds((int) $submission->id)
                : [];
        }
        if ($sectionIds === [] || $sectionIds !== $target['section_ids']) {
            return null;
        }

        $meetings = $this->scheduleQueryFor($departmentId, $semesterId)
            ->whereIn('section_id', $sectionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'section_id', 'status', 'mode', 'room_id']);
        $eligible = $meetings->whereIn('status', $scheduleStatuses)->values();
        // Every section of the package must still be at this stage. A section that moved on (or back)
        // means the package is no longer the one this review was opened on.
        $sectionsAtStage = $eligible->pluck('section_id')->unique()->count();
        if ($eligible->isEmpty() || $sectionsAtStage !== $meetings->pluck('section_id')->unique()->count()) {
            return null;
        }

        return ['submission' => $submission, 'section_ids' => $sectionIds, 'meetings' => $eligible];
    }

    /** Submission record for legacy meetings, created under the workflow lock after the scope is validated. */
    private function createLegacySubmission(int $departmentId, int $semesterId, string $status, array $sectionIds): ScheduleSubmission
    {
        $submission = ScheduleSubmission::create([
            'department_id' => $departmentId,
            'semester_id' => $semesterId,
            'revision_number' => $this->nextRevisionNumber($departmentId, $semesterId),
            'status' => $status,
            'section_count' => count($sectionIds),
            'submitted_at' => now(),
        ]);
        $submission->sections()->attach($sectionIds, ['state' => 'included']);

        return $submission;
    }

    /** Caller holds the department workflow lock, which serializes allocation. */
    private function nextRevisionNumber(int $departmentId, ?int $semesterId): int
    {
        return ((int) ScheduleSubmission::query()
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->max('revision_number')) + 1;
    }

    private function staleReviewResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This submission was already reviewed or recalled. Refresh the queue and try again.',
            'error_code' => 'submission_stage_changed',
        ], 409);
    }

    private function staleRecallResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The selected sections changed while this recall was waiting. Refresh and try again.',
            'error_code' => 'submission_stage_changed',
        ], 409);
    }

    /**
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

    private function deriveStatus(array $scheduleStatuses): string
    {
        return $this->statusDeriver->derive($scheduleStatuses);
    }

    private function departmentHasDean(int $departmentId): bool
    {
        return User::query()
            ->where('role', 'dean')
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->exists();
    }

    public function submissionSnapshot(Request $request, ScheduleSubmission $submission): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'vpaa' && (int) $user->department_id !== (int) $submission->department_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $version = $submission->snapshot_version_id === null
            ? null
            : ScheduleHistoryVersion::query()->find($submission->snapshot_version_id);
        $sectionIds = collect($version?->change_summary['selected_section_ids'] ?? [])
            ->merge($submission->sections()->pluck('sections.id'))
            ->map('intval')->unique()->values()->all();
        $items = $version === null
            ? collect()
            : ScheduleHistoryItem::query()
                ->where('history_version_id', $version->id)
                ->orderBy('id')
                ->get()
                ->filter(static function (ScheduleHistoryItem $item) use ($sectionIds): bool {
                    $row = $item->after_snapshot ?: $item->before_snapshot;

                    return $row !== null && ($sectionIds === [] || in_array((int) ($row['section_id'] ?? 0), $sectionIds, true));
                })
                ->values();

        return response()->json([
            'data' => [
                'submission_id' => $submission->id,
                'revision_number' => $submission->revision_number,
                'parent_submission_id' => $submission->parent_submission_id,
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at?->toISOString(),
                'rejection_reason' => $submission->rejection_reason,
                'available' => $version !== null,
                'schedules' => $this->describedSnapshotRows($items, 'after'),
            ],
        ]);
    }

    public function submissionChanges(Request $request, ScheduleSubmission $submission): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'vpaa' && (int) $user->department_id !== (int) $submission->department_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $logs = SchedulingAuditLog::query()
            ->with(['historyVersion.items', 'user:id,name,role'])
            ->where('schedule_submission_id', $submission->id)
            ->whereIn('action', [
                RevisionChangeRecorder::SCHEDULES_CHANGED,
                RevisionChangeRecorder::SECTION_DELETED,
                RevisionChangeRecorder::COURSE_CHANGED,
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $data = $logs->map(function (SchedulingAuditLog $log): array {
            $summary = $log->historyVersion?->change_summary ?? [];
            $sectionIds = collect($log->metadata['section_ids'] ?? [])->map('intval')->all();
            $items = ($log->historyVersion?->items ?? collect())->filter(static function (ScheduleHistoryItem $item) use ($sectionIds): bool {
                $row = $item->before_snapshot ?: $item->after_snapshot;

                return $sectionIds === [] || in_array((int) ($row['section_id'] ?? 0), $sectionIds, true);
            })->values();
            $before = $this->describedSnapshotRows($items, 'before')->keyBy('id');
            $after = $this->describedSnapshotRows($items, 'after')->keyBy('id');
            $added = array_map('intval', $summary['added_schedule_ids'] ?? []);
            $removed = array_map('intval', $summary['removed_schedule_ids'] ?? []);

            return [
                'id' => $log->id,
                'action' => $log->action,
                'operation' => $summary['operation'] ?? null,
                'created_at' => $log->created_at?->toISOString(),
                'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'role' => $log->user->role] : null,
                'section' => $summary['section'] ?? null,
                'course_changes' => $summary['course_changes'] ?? null,
                'changes' => $items->map(static function (ScheduleHistoryItem $item) use ($before, $after, $added, $removed): array {
                    $id = (int) $item->original_schedule_id;

                    return [
                        'schedule_id' => $id,
                        'change' => in_array($id, $added, true)
                            ? 'added'
                            : (in_array($id, $removed, true) || ! $after->has($id) ? 'removed' : 'updated'),
                        'before' => $before->get($id),
                        'after' => $after->get($id),
                    ];
                })->values(),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function submissionRevisionDiff(Request $request, ScheduleSubmission $submission, SubmissionStatusResolver $resolver): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'vpaa' && (int) $user->department_id !== (int) $submission->department_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $previousBySection = $resolver->previousVersionsFor($submission);
        $versionIds = collect($previousBySection)->pluck('snapshot_version_id')
            ->push($submission->snapshot_version_id)
            ->filter()->unique()->values()->all();
        $itemsByVersion = ScheduleHistoryItem::query()
            ->whereIn('history_version_id', $versionIds)
            ->orderBy('id')
            ->get()
            ->groupBy('history_version_id');
        $rowsOf = function (?int $versionId, int $sectionId) use ($itemsByVersion): Collection {
            $items = $itemsByVersion->get((int) $versionId, collect())->filter(static function (ScheduleHistoryItem $item) use ($sectionId): bool {
                return (int) (($item->after_snapshot ?: [])['section_id'] ?? 0) === $sectionId;
            });

            return $this->describedSnapshotRows($items->values(), 'after');
        };

        $sections = [];
        foreach ($previousBySection as $sectionId => $previous) {
            if ($submission->snapshot_version_id === null || $previous->snapshot_version_id === null) {
                continue;
            }
            $changes = $this->meetingChanges(
                $rowsOf((int) $previous->snapshot_version_id, $sectionId),
                $rowsOf((int) $submission->snapshot_version_id, $sectionId),
            );
            if ($changes === []) {
                continue;
            }
            $named = collect($changes)->map(fn (array $change) => $change['after'] ?? $change['before'])->first();
            $sections[] = [
                'section_id' => $sectionId,
                'section_name' => $named['section']['section_name'] ?? Sections::query()->find($sectionId)?->section_name,
                'previous_submission_id' => $previous->id,
                'previous_revision_number' => $previous->revision_number,
                'previous_status' => $previous->status,
                'previous_rejection_reason' => $previous->rejection_reason,
                'changes' => $changes,
            ];
        }
        usort($sections, static fn (array $a, array $b): int => strnatcasecmp((string) $a['section_name'], (string) $b['section_name']));

        return response()->json(['data' => [
            'submission_id' => $submission->id,
            'available' => $submission->snapshot_version_id !== null,
            'sections' => $sections,
        ]]);
    }

    /**
     * @param  Collection<int, array>  $before
     * @param  Collection<int, array>  $after
     * @return list<array{change: 'added'|'removed'|'changed', before: array|null, after: array|null}>
     */
    private function meetingChanges(Collection $before, Collection $after): array
    {
        $order = array_flip(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
        $sort = static fn (Collection $rows): Collection => $rows->sortBy([
            static fn (array $a, array $b): int => ($order[$a['day'] ?? ''] ?? 9) <=> ($order[$b['day'] ?? ''] ?? 9),
            static fn (array $a, array $b): int => strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? '')),
        ])->values();

        $meetingKey = static fn (array $row): string => SubmissionStatusResolver::meetingKey(['is_hybrid' => false] + $row);
        $removed = $sort($before)->all();
        $added = [];
        foreach ($sort($after) as $row) {
            $key = $meetingKey($row);
            $match = collect($removed)->search(static fn (array $old): bool => $meetingKey($old) === $key);
            if ($match === false) {
                $added[] = $row;
            } else {
                unset($removed[$match]);
            }
        }

        $changes = [];
        foreach ($added as $row) {
            $match = collect($removed)->search(static fn (array $old): bool => (int) ($old['course_id'] ?? 0) === (int) ($row['course_id'] ?? 0));
            if ($match === false) {
                $changes[] = ['change' => 'added', 'before' => null, 'after' => $row];
            } else {
                $changes[] = ['change' => 'changed', 'before' => $removed[$match], 'after' => $row];
                unset($removed[$match]);
            }
        }
        foreach ($removed as $row) {
            $changes[] = ['change' => 'removed', 'before' => $row, 'after' => null];
        }
        usort($changes, static fn (array $a, array $b): int => strnatcasecmp(
            (string) (($a['after'] ?? $a['before'])['course']['course_code'] ?? ''),
            (string) (($b['after'] ?? $b['before'])['course']['course_code'] ?? ''),
        ));

        return $changes;
    }

    /**
     * @param  Collection<int, ScheduleHistoryItem>  $items
     * @param  'before'|'after'  $side
     * @return Collection<int, array>
     */
    private function describedSnapshotRows(Collection $items, string $side): Collection
    {
        $rows = $items->map(static function (ScheduleHistoryItem $item) use ($side): ?array {
            $row = $side === 'before' ? $item->before_snapshot : $item->after_snapshot;
            if (! $row) {
                return null;
            }
            $metadata = $item->snapshot_metadata ?? [];

            return [
                'row' => $row,
                'names' => $metadata[$side.'_names'] ?? (array_key_exists('course_code', $metadata) ? $metadata : null),
            ];
        })->filter()->values();

        $missing = $rows->filter(static fn (array $entry): bool => $entry['names'] === null)->pluck('row');
        $live = $missing->isEmpty() ? [] : $this->descriptors->for($missing);

        return $rows->map(static function (array $entry) use ($live): array {
            $row = $entry['row'];
            $names = $entry['names'] ?? ($live[(int) ($row['id'] ?? 0)] ?? []);

            return $row + [
                'course' => ($names['course_code'] ?? null) === null ? null : [
                    'id' => $row['course_id'] ?? null,
                    'course_code' => $names['course_code'],
                    'course_name' => $names['course_name'] ?? null,
                    'course_category' => $names['course_category'] ?? null,
                    'units' => $names['units'] ?? null,
                    'lecture_hours' => $names['lecture_hours'] ?? null,
                    'lab_hours' => $names['lab_hours'] ?? null,
                ],
                'room' => ($names['room_name'] ?? null) === null
                    ? null
                    : ['id' => $row['room_id'] ?? null, 'room_code' => $names['room_name']],
                'faculty' => ($names['faculty_name'] ?? null) === null ? null : [
                    'id' => $row['faculty_id'] ?? null,
                    'first_name' => $names['faculty_name'],
                    'last_name' => '',
                ],
                'section' => ($names['section_name'] ?? null) === null
                    ? null
                    : ['id' => $row['section_id'] ?? null, 'section_name' => $names['section_name']],
            ];
        })->values();
    }

    public function scheduleStatus(int $id, Request $request): JsonResponse
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
            'has_dean' => $this->departmentHasDean((int) $department->id),
            'cross_department_unlocked' => $this->crossDepartmentGate->allowsSectionStatuses($result->pluck('status')->all()),
            'cross_department_pending' => $this->crossDepartmentPendingCount(
                (int) $department->id,
                $activeSemesterId,
                $request->user()?->role === 'program_head' ? (int) ($request->user()?->program_id ?? 0) : null,
            ),
        ]);
    }

    private function crossDepartmentPendingCount(int $departmentId, ?int $activeSemesterId, ?int $programId = null): int
    {
        if ($activeSemesterId === null) {
            return 0;
        }

        return Schedule::query()
            ->where('semester_id', $activeSemesterId)
            ->whereIn('status', SchedulingPolicy::INSTRUCTOR_ASSIGNABLE_STATUSES)
            ->whereNull('faculty_id')
            ->where(fn ($owner) => $owner
                ->where('department_id', '!=', $departmentId)
                ->orWhereHas('course', fn ($course) => $course->where('department_id', $departmentId)))
            ->whereHas('course', fn ($course) => $course
                ->where('status', 'active')
                ->delegatedTo($departmentId, $programId))
            ->distinct()
            ->get(['section_id', 'course_id'])
            ->count();
    }

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

    public function submitSchedules(int $id, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'vpaa' && (int) $user->department_id !== $id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($user->role === 'program_head' && $user->program_id === null) {
            return response()->json(['message' => 'Program Head accounts must be assigned to a program.'], 403);
        }

        $namedSectionIds = Sections::query()
            ->whereIn('id', array_map('intval', array_filter((array) $request->input('section_ids', []), 'is_numeric')))
            ->where('department_id', $id)
            ->pluck('id')
            ->all();
        if (! $this->authorization->sectionIdsWritable($request, $namedSectionIds)) {
            return response()->json(['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE], 403);
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
        $writableProgramIds = $this->authorization->writableProgramIds($request);

        // Readiness is decided under the workflow lock: a submission or recall that commits first changes
        // which sections are ready, and a duplicate request then finds nothing left to submit.
        $result = DB::transaction(function () use ($id, $validated, $writableProgramIds, $request, $department, $activeSemesterId, $user): array|JsonResponse {
            $this->lockDepartmentWorkflow($id);

            $sections = Sections::with(['schedules' => function ($query) use ($activeSemesterId) {
                $query->select('id', 'section_id', 'status')
                    ->when($activeSemesterId, fn ($q) => $q->where('semester_id', $activeSemesterId));
            }])
                ->where('department_id', $id)
                ->whereIn('program_id', $writableProgramIds)
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

            $readyStatuses = self::SUBMITTABLE_STATUSES;
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

            foreach ($sections as $section) {
                $statuses = $section->schedules->pluck('status')->filter()->unique()->values();
                if ($statuses->isNotEmpty() && $statuses->every(
                    static fn (string $status): bool => in_array($status, $readyStatuses, true)
                )) {
                    $readySectionIds->push((int) $section->id);

                    continue;
                }

                if ($statuses->contains(
                    static fn (string $status): bool => in_array($status, $protectedStatuses, true)
                )) {
                    $protectedSectionIds->push((int) $section->id);

                    continue;
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
                    'message' => 'No plotted schedule sections are ready for submission.',
                ], 422);
            }

            if ($protectedSectionIds->isEmpty() && ! empty($blockedYears)) {
                $blockedYears = array_values(array_unique($blockedYears));
                sort($blockedYears);
                $yearLabels = array_map(static fn (int $year): string => "Year {$year}", $blockedYears);

                return response()->json([
                    'message' => 'Cannot submit: some year levels still have sections with nothing plotted.',
                    'blocked_years' => $blockedYears,
                    'hint' => 'Plot '.implode(', ', $yearLabels).' before submitting the initial schedule.',
                ], 422);
            }

            $sectionIds = $readySectionIds->all();

            $parentSubmission = ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('semester_id', $activeSemesterId)
                ->whereIn('status', ['withdrawn', 'partially_withdrawn', 'rejected_by_dean', 'rejected_by_vpaa'])
                ->whereHas('sections', fn ($query) => $query->whereIn('sections.id', $sectionIds))
                ->latest('revision_number')
                ->first();
            $revisionNumber = $this->nextRevisionNumber($department->id, $activeSemesterId);
            $submission = ScheduleSubmission::create([
                'department_id' => $department->id,
                'semester_id' => $activeSemesterId,
                'parent_submission_id' => $parentSubmission?->id,
                'revision_number' => $revisionNumber,
                'status' => 'pending_dean',
                'section_count' => count($sectionIds),
                'subject_count' => Schedule::whereIn('section_id', $sectionIds)
                    ->where('semester_id', $activeSemesterId)
                    ->whereIn('status', self::SUBMITTABLE_STATUSES)
                    ->distinct()
                    ->count('course_id'),
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'signatories' => $this->signatories->initial($department->id, $activeSemesterId, $sectionIds),
            ]);
            $submission->sections()->attach($sectionIds, ['state' => 'included']);

            $updated = Schedule::whereIn('section_id', $sectionIds)
                ->when($activeSemesterId, fn ($query) => $query->where('semester_id', $activeSemesterId))
                ->whereIn('status', self::SUBMITTABLE_STATUSES)
                ->update([
                    'status' => 'submitted',
                    'updated_at' => now(),
                ]);
            if ($updated > 0) {
                $snapshotVersionId = $this->recordWorkflowAudit($request, 'schedule_submitted', $department->id, $activeSemesterId, [
                    'schedules_updated' => $updated,
                    'selected_section_ids' => $sectionIds,
                    'revision_number' => $submission->revision_number,
                    'parent_submission_id' => $submission->parent_submission_id,
                ], $submission->id);
                $submission->update(['snapshot_version_id' => $snapshotVersionId]);
            }

            return compact('updated', 'submission', 'sectionIds');
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        ['updated' => $updated, 'submission' => $submission, 'sectionIds' => $sectionIds] = $result;
        $this->forgetWorkflowCaches();

        if ($updated > 0) {
            $semester = Semester::query()->find($activeSemesterId);
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
            'schedule_submission_id' => ['sometimes', 'integer'],
            'override_room_tba' => ['sometimes', 'boolean'],
            'override_reason' => ['required_if:override_room_tba,true', 'nullable', 'string', 'max:2000'],
        ]);
        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();
        $semesterId = $this->activeSemesterId();
        $target = $this->reviewTarget(
            $id,
            $semesterId,
            ['pending_dean'],
            ['submitted'],
            isset($validated['schedule_submission_id']) ? (int) $validated['schedule_submission_id'] : null,
        );
        if ($target === null) {
            return response()->json(['message' => 'No schedule submission is pending Dean approval.'], 422);
        }

        $result = DB::transaction(function () use ($id, $semesterId, $target, $request, $user, $now, $validated): array|JsonResponse {
            $this->lockDepartmentWorkflow($id);
            $scope = $this->lockedReviewScope($id, $semesterId, $target, ['pending_dean'], ['submitted']);
            if ($scope === null) {
                return $this->staleReviewResponse();
            }
            $sectionIds = $scope['section_ids'];

            if (($openConflicts = $this->openConflictsIn($id, $sectionIds, $semesterId)) !== []) {
                return $this->openConflictsResponse($openConflicts);
            }
            $override = $scope['meetings']->contains(
                static fn (Schedule $meeting): bool => $meeting->mode === 'on-site' && $meeting->room_id === null
            );
            if ($override && ! ($validated['override_room_tba'] ?? false)) {
                return response()->json([
                    'message' => 'This schedule has Room TBA meetings. Approve it with a Room TBA override and a reason.',
                    'error_code' => 'room_tba_override_required',
                ], 422);
            }

            $submission = $scope['submission'] ?? $this->createLegacySubmission($id, $semesterId, 'pending_dean', $sectionIds);
            $updated = Schedule::query()->whereKey($scope['meetings']->modelKeys())->update([
                'status' => $override ? 'conditionally_approved' : 'approved_by_dean',
                'updated_at' => $now,
            ]);
            $submission->update([
                'status' => $submission->status === 'partially_withdrawn' ? 'partially_withdrawn' : 'pending_vpaa',
                'dean_reviewed_by' => $user->id,
                'dean_reviewed_at' => $now,
                'rejection_reason' => null,
                'signatories' => $this->signatories->stamp($submission, ['reviewed'], $user, false, $now),
                'approval_override' => $override,
                'approval_override_reason' => $override ? ($validated['override_reason'] ?? null) : null,
            ]);
            $this->recordWorkflowAudit($request, 'schedule_approved_by_dean', $id, $semesterId, [
                'schedules_updated' => $updated,
                'selected_section_ids' => $sectionIds,
                'approval_override' => $override,
                'approval_override_reason' => $override ? ($validated['override_reason'] ?? null) : null,
            ], $submission->id);

            return ['updated' => $updated, 'submission_id' => (int) $submission->id, 'override' => $override];
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        ['updated' => $updated, 'submission_id' => $submissionId, 'override' => $override] = $result;
        $this->forgetWorkflowCaches();

        $semester = Semester::query()->find($semesterId);
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
            ['schedules_updated' => $updated, 'schedule_submission_id' => $submissionId],
        );

        return response()->json([
            'message' => $override
                ? 'Department schedule conditionally approved with Room TBA override.'
                : 'Department schedule approved by Dean and forwarded to VPAA.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submissionId,
        ]);
    }

    public function returnByDean(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['dean', 'vpaa'])) {
            return $forbidden;
        }

        $validated = $request->validate([
            'schedule_submission_id' => ['sometimes', 'integer'],
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();
        $semesterId = $this->activeSemesterId();
        $target = $this->reviewTarget(
            $id,
            $semesterId,
            ['pending_dean'],
            ['submitted'],
            isset($validated['schedule_submission_id']) ? (int) $validated['schedule_submission_id'] : null,
        );
        if ($target === null) {
            return response()->json(['message' => 'No schedule submission is pending Dean approval.'], 422);
        }

        $result = DB::transaction(function () use ($id, $semesterId, $target, $request, $user, $now, $validated): array|JsonResponse {
            $this->lockDepartmentWorkflow($id);
            $scope = $this->lockedReviewScope($id, $semesterId, $target, ['pending_dean'], ['submitted']);
            if ($scope === null) {
                return $this->staleReviewResponse();
            }

            $submission = $scope['submission'] ?? $this->createLegacySubmission($id, $semesterId, 'pending_dean', $scope['section_ids']);
            $updated = Schedule::query()->whereKey($scope['meetings']->modelKeys())->update([
                'status' => 'rejected_by_dean',
                'updated_at' => $now,
            ]);
            $submission->update([
                'status' => 'rejected_by_dean',
                'dean_reviewed_by' => $user->id,
                'dean_reviewed_at' => $now,
                'rejection_reason' => $validated['rejection_reason'],
                'signatories' => $this->signatories->stamp($submission, ['reviewed'], $user, true, $now),
            ]);
            $this->recordWorkflowAudit($request, 'schedule_returned_by_dean', $id, $semesterId, [
                'schedules_updated' => $updated,
                'rejection_reason' => $validated['rejection_reason'],
                'selected_section_ids' => $scope['section_ids'],
            ], $submission->id);

            return ['updated' => $updated, 'submission_id' => (int) $submission->id];
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        ['updated' => $updated, 'submission_id' => $submissionId] = $result;
        $this->forgetWorkflowCaches();

        $semester = Semester::query()->find($semesterId);
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
            ['schedules_updated' => $updated, 'schedule_submission_id' => $submissionId],
        );

        return response()->json([
            'message' => 'Department schedule returned by Dean for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submissionId,
        ]);
    }

    /**
     * Read-only: whether every selected section can be recalled now, and which submission owns each
     * one. Run before the transaction as the request's expectation, and again under the workflow lock,
     * where a different owner means the selection moved to another revision while the recall waited.
     *
     * The owners map is section ID => owning submission ID; null marks legacy meetings whose
     * submission is created under the lock.
     *
     * @param  list<int>  $sectionIds
     * @return array{stage: string, owners: array<int, ?int>, legacy_status: string, legacy_section_ids: list<int>}|JsonResponse
     */
    private function recallPlan(int $departmentId, ?int $semesterId, array $sectionIds): array|JsonResponse
    {
        $query = $this->scheduleQueryFor($departmentId, $semesterId)->whereIn('section_id', $sectionIds);

        if ((clone $query)->where('status', 'finalized')->exists()) {
            return response()->json([
                'message' => 'Finalized schedules cannot be recalled. Use Reassignment to reopen them first.',
            ], 422);
        }

        $withdrawable = (clone $query)->whereIn('status', self::WITHDRAWABLE_STATUSES)->get(['section_id', 'status']);
        if ($withdrawable->isEmpty()) {
            return response()->json([
                'message' => 'No submitted or VPAA-approved schedule is available to recall.',
            ], 422);
        }
        $withdrawableSectionIds = $withdrawable->pluck('section_id')->map('intval')->unique()->all();
        if (array_diff($sectionIds, $withdrawableSectionIds) !== []) {
            return response()->json([
                'message' => 'One or more selected sections cannot be recalled right now.',
            ], 422);
        }

        $statuses = $withdrawable->pluck('status')->unique();
        $stage = $statuses->contains(fn (string $status): bool => in_array($status, ['approved', 'faculty_assignment', 'reassignment'], true))
            ? 'vpaa_approved'
            : ($statuses->contains(
                fn (string $status): bool => in_array($status, ['approved_by_dean', 'conditionally_approved'], true)
            ) ? 'vpaa_review' : 'dean_review');
        $legacyStatus = $stage === 'vpaa_approved'
            ? 'approved'
            : ($stage === 'vpaa_review' ? 'pending_vpaa' : 'pending_dean');

        $openStatuses = ['pending_dean', 'pending_vpaa', 'approved', 'partially_withdrawn'];
        $owners = [];
        DB::table('schedule_submission_sections')
            ->join('schedule_submissions', 'schedule_submissions.id', '=', 'schedule_submission_sections.schedule_submission_id')
            ->where('schedule_submissions.department_id', $departmentId)
            ->where('schedule_submissions.semester_id', $semesterId)
            ->whereIn('schedule_submissions.status', $openStatuses)
            ->where('schedule_submission_sections.state', 'included')
            ->whereIn('schedule_submission_sections.section_id', $sectionIds)
            ->orderByDesc('schedule_submissions.revision_number')
            ->get(['schedule_submission_sections.section_id', 'schedule_submissions.id'])
            ->each(function (object $row) use (&$owners): void {
                $owners[(int) $row->section_id] ??= (int) $row->id;
            });

        // Meetings that predate submission records get one created for them, as long as the department
        // has no open submission at all; otherwise a selected section with no owner cannot be recalled.
        $legacySectionIds = [];
        $missing = array_values(array_diff($sectionIds, array_keys($owners)));
        if ($missing !== [] && ! ScheduleSubmission::query()
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->whereIn('status', $openStatuses)
            ->exists()) {
            $legacySectionIds = $this->scheduleQueryFor($departmentId, $semesterId)
                ->whereIn('status', self::WITHDRAWABLE_STATUSES)
                ->distinct()
                ->orderBy('section_id')
                ->pluck('section_id')
                ->map('intval')
                ->all();
            foreach (array_intersect($missing, $legacySectionIds) as $sectionId) {
                $owners[$sectionId] = null;
            }
        }
        if (array_diff($sectionIds, array_keys($owners)) !== []) {
            return response()->json(['message' => 'No approval submission contains the selected sections.'], 422);
        }
        ksort($owners);

        return [
            'stage' => $stage,
            'owners' => $owners,
            'legacy_status' => $legacyStatus,
            'legacy_section_ids' => $legacySectionIds,
        ];
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
        $semesterId = $this->activeSemesterId();
        $allowedSectionIds = $this->sectionIdsFor($id, $semesterId);
        if (! $this->authorization->sectionIdsWritable($request, array_values(array_intersect($sectionIds, $allowedSectionIds)))) {
            return response()->json(['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE], 403);
        }
        $invalidSectionIds = array_diff($sectionIds, $allowedSectionIds);
        if (! empty($invalidSectionIds)) {
            return response()->json([
                'message' => 'One or more selected sections do not belong to this department.',
            ], 422);
        }

        $expected = $this->recallPlan($id, $semesterId, $sectionIds);
        if ($expected instanceof JsonResponse) {
            return $expected;
        }

        $result = DB::transaction(function () use ($id, $semesterId, $sectionIds, $expected, $request, $user): array|JsonResponse {
            $this->lockDepartmentWorkflow($id);
            $plan = $this->recallPlan($id, $semesterId, $sectionIds);
            if ($plan instanceof JsonResponse) {
                return $plan;
            }
            if ($plan['owners'] !== $expected['owners'] || $plan['legacy_section_ids'] !== $expected['legacy_section_ids']) {
                return $this->staleRecallResponse();
            }

            $owners = $plan['owners'];
            if (in_array(null, $owners, true)) {
                $legacy = $this->createLegacySubmission($id, $semesterId, $plan['legacy_status'], $plan['legacy_section_ids']);
                $owners = array_map(static fn (?int $owner): int => $owner ?? (int) $legacy->id, $owners);
            }
            $submissionSections = [];
            foreach ($owners as $sectionId => $submissionId) {
                $submissionSections[$submissionId][] = $sectionId;
            }
            ksort($submissionSections);
            $affectedSubmissions = ScheduleSubmission::query()
                ->whereKey(array_keys($submissionSections))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $selected = fn () => $this->scheduleQueryFor($id, $semesterId)->whereIn('section_id', $sectionIds);
            $selected()
                ->whereIn('status', self::WITHDRAWABLE_STATUSES)
                ->where('faculty_assignment_done', true)
                ->whereDoesntHave('course', fn ($courseQuery) => $courseQuery
                    ->whereNotNull('teaching_department_id')
                    ->whereColumn('courses.teaching_department_id', '!=', 'schedules.department_id'))
                ->update(['faculty_assignment_done' => false, 'updated_at' => now()]);

            $completed = $selected()
                ->whereIn('status', self::WITHDRAWABLE_STATUSES)
                ->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);

            $revision = $selected()
                ->where('status', 'completed')
                ->update([
                    'status' => 'revision',
                    'updated_at' => now(),
                ]);

            foreach ($affectedSubmissions as $submission) {
                $submission->sections()->updateExistingPivot($submissionSections[(int) $submission->id], [
                    'state' => 'withdrawn',
                    'updated_at' => now(),
                ]);
                $remainingIncluded = $this->currentIncludedSectionIds((int) $submission->id) !== [];
                $submission->update([
                    'status' => $remainingIncluded ? 'partially_withdrawn' : 'withdrawn',
                    'withdrawn_by' => $user->id,
                    'withdrawn_at' => now(),
                    // A full recall closes this revision with the officials of the day; a resubmission starts a new one.
                    ...($remainingIncluded ? [] : ['signatories' => $this->signatories->stamp($submission, [], $user, true)]),
                ]);
            }

            $submissionIds = $affectedSubmissions->pluck('id')->map('intval')->values()->all();
            $primarySubmissionId = $affectedSubmissions->sortByDesc('revision_number')->first()?->id;
            if ($revision > 0) {
                $this->recordWorkflowAudit($request, 'schedule_withdrawn', $id, $semesterId, [
                    'schedules_updated' => $revision,
                    'sections_unlocked' => count($sectionIds),
                    'withdrawal_stage' => $plan['stage'],
                    'instructors_released' => 0,
                    'selected_section_ids' => $sectionIds,
                    'schedule_submission_ids' => $submissionIds,
                    'submission_section_ids' => $submissionSections,
                ], $primarySubmissionId);
            }

            // What the reviewers are told: which sections left the affected submissions, and how many remain.
            $membership = DB::table('schedule_submission_sections')
                ->whereIn('schedule_submission_id', $submissionIds)
                ->selectRaw("count(*) as original, sum(case when state = 'included' then 1 else 0 end) as remaining")
                ->first();

            return [
                'completed' => $completed,
                'revision' => $revision,
                'instructors_released' => 0,
                'submission_ids' => $submissionIds,
                'primary_submission_id' => $primarySubmissionId,
                'withdrawal_stage' => $plan['stage'],
                'recalled_section_names' => Sections::query()->whereKey($sectionIds)->orderBy('section_name')->pluck('section_name')->all(),
                'original_section_count' => (int) $membership->original,
                'remaining_section_count' => (int) $membership->remaining,
            ];
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $updated = $result;
        $withdrawalStage = $updated['withdrawal_stage'];
        $primarySubmissionId = $updated['primary_submission_id'];

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        $semester = Semester::query()->find($semesterId);
        // A recall during review takes sections out of a reviewer's queue; say whose, which, and what remains.
        $reviewer = ['dean_review' => 'Dean', 'vpaa_review' => 'VPAA'][$withdrawalStage] ?? null;
        $message = $this->notifications->departmentWorkflowMessage('recalled', $department, $semester, $user, $updated['revision'])
            .' Recalled: '.implode(', ', $updated['recalled_section_names']).'.';
        if ($reviewer !== null) {
            $message .= $updated['remaining_section_count'] > 0
                ? " {$updated['remaining_section_count']} of {$updated['original_section_count']} sections remain for {$reviewer} approval."
                : ' No sections remain for approval.';
        }
        $this->notifications->notifyRoles(
            ['vpaa', 'dean', 'secretary', 'program_head'],
            'schedule_withdrawn',
            $reviewer !== null ? "Sections recalled from {$reviewer} review" : 'Schedule submission recalled',
            $message,
            $user,
            $department->id,
            $semester?->id,
            null,
            [
                'schedules_updated' => $updated['revision'],
                'sections_unlocked' => count($sectionIds),
                'selected_section_ids' => $sectionIds,
                'recalled_section_names' => $updated['recalled_section_names'],
                'original_section_count' => $updated['original_section_count'],
                'remaining_section_count' => $updated['remaining_section_count'],
                'withdrawal_stage' => $withdrawalStage,
                'instructors_released' => $updated['instructors_released'],
                'schedule_submission_id' => $primarySubmissionId,
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
            'schedule_submission_id' => $primarySubmissionId,
            'schedule_submission_ids' => $updated['submission_ids'],
        ]);
    }

    public function approveByVpaa(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['vpaa'])) {
            return $forbidden;
        }

        $validated = $request->validate([
            'schedule_submission_id' => ['sometimes', 'integer'],
        ]);
        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();
        $semesterId = $this->activeSemesterId();
        $target = $this->reviewTarget(
            $id,
            $semesterId,
            ['pending_vpaa'],
            ['approved_by_dean', 'conditionally_approved'],
            isset($validated['schedule_submission_id']) ? (int) $validated['schedule_submission_id'] : null,
        );
        if ($target === null) {
            return response()->json(['message' => 'No schedule submission is pending VPAA approval.'], 422);
        }

        $result = DB::transaction(function () use ($id, $semesterId, $target, $request, $user, $now): array|JsonResponse {
            $this->lockDepartmentWorkflow($id);
            $scope = $this->lockedReviewScope($id, $semesterId, $target, ['pending_vpaa'], ['approved_by_dean', 'conditionally_approved']);
            if ($scope === null) {
                return $this->staleReviewResponse();
            }
            if (($openConflicts = $this->openConflictsIn($id, $scope['section_ids'], $semesterId)) !== []) {
                return $this->openConflictsResponse($openConflicts);
            }

            $submission = $scope['submission'] ?? $this->createLegacySubmission($id, $semesterId, 'pending_vpaa', $scope['section_ids']);
            $updated = Schedule::query()->whereKey($scope['meetings']->modelKeys())->update([
                'status' => 'faculty_assignment',
                'updated_at' => $now,
            ]);
            $submission->update([
                'status' => $submission->status === 'partially_withdrawn' ? 'partially_withdrawn' : 'approved',
                'vpaa_reviewed_by' => $user->id,
                'vpaa_reviewed_at' => $now,
                'rejection_reason' => null,
                'signatories' => $this->signatories->stamp($submission, ['recommended', 'approved'], $user, true, $now),
            ]);
            $this->recordWorkflowAudit($request, 'schedule_approved_by_vpaa', $id, $semesterId, [
                'schedules_updated' => $updated,
                'selected_section_ids' => $scope['section_ids'],
            ], $submission->id);

            return ['updated' => $updated, 'submission_id' => (int) $submission->id];
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        ['updated' => $updated, 'submission_id' => $submissionId] = $result;
        $this->forgetWorkflowCaches(affectsAssignments: true);

        $semester = Semester::query()->find($semesterId);
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
            ['schedules_updated' => $updated, 'schedule_submission_id' => $submissionId],
        );

        return response()->json([
            'message' => 'Department schedule approved by VPAA.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submissionId,
        ]);
    }

    public function returnByVpaa(int $id, Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRoleCanActOnDepartment($request, $id, ['vpaa'])) {
            return $forbidden;
        }

        $validated = $request->validate([
            'schedule_submission_id' => ['sometimes', 'integer'],
            'rejection_reason' => 'required|string|max:2000',
        ]);

        $department = Departments::findOrFail($id);
        $user = $request->user();
        $now = now();
        $semesterId = $this->activeSemesterId();
        $target = $this->reviewTarget(
            $id,
            $semesterId,
            ['pending_vpaa'],
            ['approved_by_dean', 'conditionally_approved'],
            isset($validated['schedule_submission_id']) ? (int) $validated['schedule_submission_id'] : null,
        );
        if ($target === null) {
            return response()->json(['message' => 'No schedule submission is pending VPAA approval.'], 422);
        }

        $result = DB::transaction(function () use ($id, $semesterId, $target, $request, $user, $now, $validated): array|JsonResponse {
            $this->lockDepartmentWorkflow($id);
            $scope = $this->lockedReviewScope($id, $semesterId, $target, ['pending_vpaa'], ['approved_by_dean', 'conditionally_approved']);
            if ($scope === null) {
                return $this->staleReviewResponse();
            }

            $submission = $scope['submission'] ?? $this->createLegacySubmission($id, $semesterId, 'pending_vpaa', $scope['section_ids']);
            $updated = Schedule::query()->whereKey($scope['meetings']->modelKeys())->update([
                'status' => 'rejected',
                'updated_at' => $now,
            ]);
            $submission->update([
                'status' => 'rejected_by_vpaa',
                'vpaa_reviewed_by' => $user->id,
                'vpaa_reviewed_at' => $now,
                'rejection_reason' => $validated['rejection_reason'],
                'signatories' => $this->signatories->stamp($submission, ['recommended'], $user, true, $now),
            ]);
            $this->recordWorkflowAudit($request, 'schedule_returned_by_vpaa', $id, $semesterId, [
                'schedules_updated' => $updated,
                'rejection_reason' => $validated['rejection_reason'],
                'selected_section_ids' => $scope['section_ids'],
            ], $submission->id);

            return ['updated' => $updated, 'submission_id' => (int) $submission->id];
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        ['updated' => $updated, 'submission_id' => $submissionId] = $result;
        $this->forgetWorkflowCaches(affectsAssignments: true);

        $semester = Semester::query()->find($semesterId);
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
            ['schedules_updated' => $updated, 'schedule_submission_id' => $submissionId],
        );

        return response()->json([
            'message' => 'Department schedule returned by VPAA for revision.',
            'department_name' => $department->department_name,
            'schedules_updated' => $updated,
            'schedule_submission_id' => $submissionId,
        ]);
    }

    private function recordWorkflowAudit(
        Request $request,
        string $action,
        int $departmentId,
        ?int $semesterId,
        array $metadata = [],
        ?int $submissionId = null,
    ): ?int {
        $historyGroupId = (string) Str::uuid();
        $metadata['history_group_id'] = $historyGroupId;
        $schedules = collect();

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
                $this->descriptors->for($schedules),
            );
            // The history entry keeps the officials who held each slot when this action happened.
            $submission = $submissionId !== null ? ScheduleSubmission::query()->find($submissionId) : null;
            if ($submission !== null) {
                $version->update(['signatories' => $this->signatories->frozenCopy($submission)]);
            }
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

        return $version?->id;
    }
}
