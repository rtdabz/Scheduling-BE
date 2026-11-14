<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Departments;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\SystemNotificationService;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Permanent Reset for explicitly selected sections of one semester.
 *
 * Eligibility is the plotting-editable policy Generate Again's replacement also uses
 * (Schedule::PLOTTING_EDITABLE_STATUSES): sections still being prepared (`draft`), completed but
 * unsubmitted (`completed`), returned (`rejected`, `rejected_by_dean`), or recalled (`revision`).
 * Each section is judged on its own live rows and its own membership in an open submission, so a
 * recalled section is eligible even while the rest of its submission is still under review. Any
 * blocked section rejects the whole request with a reason per section.
 * Eligible schedules and every split row of theirs (soft-deleted ones included) are physically
 * deleted. Reset never archives, and it never writes, changes, or deletes Schedule History,
 * submissions, approvals, or earlier audit records: it records one metadata-only Activity Log
 * event and nothing else.
 */
final class ScheduleResetService
{
    public const ELIGIBLE_STATUSES = Schedule::PLOTTING_EDITABLE_STATUSES;

    public const ACTION = 'schedule_reset';

    /** Same "open submission" set the workflow uses to claim sections (DepartmentScheduleController). */
    private const OPEN_SUBMISSION_STATUSES = ['pending_dean', 'pending_vpaa', 'approved', 'partially_withdrawn'];

    public function __construct(
        private readonly ScheduleAuthorizationService $authorization,
        private readonly SchedulingScopeLock $scheduleWriteLock,
        private readonly SystemNotificationService $notifications,
    ) {}

    /**
     * Read-only review of what a reset would delete, with a token that pins this exact target.
     *
     * @param  list<int>  $sectionIds
     * @return array<string, mixed>
     */
    public function preview(Request $request, int $semesterId, array $sectionIds): array
    {
        return $this->describe($this->plan($request, $semesterId, $sectionIds, locked: false));
    }

    /**
     * Deletes the reviewed target, or nothing. Locks: the semester scheduling lock first (as every
     * schedule write takes it), then, as the first statement of the transaction, the department
     * workflow row lock used by submission, recall, and review. The whole plan is recomputed under
     * both locks and must still match the reviewed token.
     *
     * @param  list<int>  $sectionIds
     * @return array<string, mixed>
     */
    public function reset(Request $request, int $semesterId, array $sectionIds, string $expectedToken): array
    {
        // Unlocked preflight: fails fast and names the departments whose workflow rows to lock.
        $preflight = $this->plan($request, $semesterId, $sectionIds, locked: false);
        $departmentIds = $preflight['sections']->pluck('department_id')->map('intval')->unique()->sort()->values()->all();

        $plan = $this->scheduleWriteLock->execute([$semesterId], fn (): array => DB::transaction(
            function () use ($request, $semesterId, $sectionIds, $expectedToken, $departmentIds): array {
                foreach ($departmentIds as $departmentId) {
                    Departments::query()->whereKey($departmentId)->lockForUpdate()->value('id');
                }

                $plan = $this->plan($request, $semesterId, $sectionIds, locked: true);
                if (! hash_equals($plan['token'], $expectedToken)) {
                    throw new ScheduleResetRejected(409, [
                        'message' => 'The schedules for these sections changed after you reviewed them. Review the updated counts and confirm again.',
                        'code' => 'reset_target_changed',
                        'preview' => $this->describe($plan),
                    ]);
                }

                $scheduleIds = $plan['schedules']->modelKeys();
                // Query-builder deletes fire no model events, so no archive, history, or revision
                // recorder can run. Split children go first, including already soft-deleted ones.
                ScheduleSplit::withTrashed()->whereIn('schedule_id', $scheduleIds)->forceDelete();
                $deleted = Schedule::query()
                    ->whereIn('id', $scheduleIds)
                    ->whereIn('status', self::ELIGIBLE_STATUSES)
                    ->whereNull('deleted_at')
                    ->forceDelete();
                if ($deleted !== count($scheduleIds)) {
                    throw new ScheduleResetRejected(409, [
                        'message' => 'Some schedules changed while they were being reset. Nothing was deleted. Refresh and try again.',
                        'code' => 'reset_target_changed',
                    ]);
                }

                $counts = $this->counts($plan);
                SchedulingAuditLog::create([
                    'user_id' => $request->user()?->id,
                    'semester_id' => $semesterId,
                    'section_id' => count($plan['section_ids']) === 1 ? $plan['section_ids'][0] : null,
                    'department_id' => $departmentIds[0] ?? null,
                    'action' => self::ACTION,
                    'metadata' => [
                        'permanent' => true,
                        'section_ids' => $plan['section_ids'],
                        'department_ids' => $departmentIds,
                        'schedule_ids' => array_map('intval', $scheduleIds),
                        ...$counts,
                    ],
                    'created_at' => now(),
                ]);

                return $plan;
            },
        ));

        // After commit only: caches and the existing cross-department release notification.
        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);
        $crossDepartment = $plan['cross_department_released'];
        if ($crossDepartment->isNotEmpty() && $request->user() !== null) {
            $this->notifications->notifyCrossDepartmentInstructorsReleased($crossDepartment, $request->user(), 'reset');
        }

        return [
            'message' => 'Schedules were permanently deleted. Schedule History was not changed.',
            ...$this->describe($plan),
            'deleted_schedule_ids' => array_map('intval', $plan['schedules']->modelKeys()),
        ];
    }

    /**
     * @param  list<int>  $sectionIds
     * @return array{semester: Semester, sections: Collection<int, Sections>, section_ids: list<int>, schedules: Collection<int, Schedule>, split_ids: list<int>, archived_split_ids: list<int>, assigned: Collection<int, Schedule>, cross_department_released: Collection<int, Schedule>, token: string}
     */
    private function plan(Request $request, int $semesterId, array $sectionIds, bool $locked): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        sort($sectionIds);

        $semester = Semester::query()->find($semesterId);
        if ($semester === null) {
            throw new ScheduleResetRejected(422, ['message' => 'The selected semester no longer exists.', 'code' => 'reset_scope_invalid']);
        }
        if (! $semester->is_active) {
            throw new ScheduleResetRejected(422, [
                'message' => 'Only the active semester\'s schedules can be reset. Past terms stay in Schedule History.',
                'code' => 'reset_scope_invalid',
            ]);
        }

        $sections = Sections::query()->whereIn('id', $sectionIds)->orderBy('id')
            ->when($locked, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'section_name', 'year_level', 'department_id', 'program_id', 'semester_id']);
        if ($sections->count() !== count($sectionIds) || $sections->contains(fn (Sections $section): bool => (int) $section->semester_id !== $semesterId)) {
            throw new ScheduleResetRejected(422, [
                'message' => 'One or more selected sections no longer belong to this semester. Refresh and select again.',
                'code' => 'reset_scope_invalid',
            ]);
        }
        $scope = $this->authorization->departmentScope($request);
        if ($scope !== null && $sections->contains(fn (Sections $section): bool => (int) $section->department_id !== $scope)) {
            throw new ScheduleResetRejected(403, ['message' => 'You can only manage schedules for your department.']);
        }
        if (! $this->authorization->sectionIdsWritable($request, $sectionIds)) {
            throw new ScheduleResetRejected(403, ['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE]);
        }

        $rows = Schedule::query()
            ->where('semester_id', $semesterId)
            ->whereIn('section_id', $sectionIds)
            ->orderBy('id')
            ->when($locked, fn ($query) => $query->lockForUpdate())
            ->get();

        $this->rejectBlockedSections($semesterId, $sections, $rows);

        $empty = collect($sectionIds)->diff($rows->pluck('section_id')->map('intval'));
        if ($empty->isNotEmpty()) {
            throw new ScheduleResetRejected(422, [
                'message' => $this->sectionNames($sections, $empty->all()).' '.($empty->count() === 1 ? 'has' : 'have')
                    .' no schedules to reset. Refresh and select again.',
                'code' => 'reset_scope_invalid',
                'section_ids' => $empty->values()->all(),
            ]);
        }

        $scheduleIds = $rows->modelKeys();
        if (! $this->authorization->scheduleIdsBelongToDepartment($request, $scheduleIds)) {
            throw new ScheduleResetRejected(403, ['message' => 'You can only manage schedules for your department.']);
        }
        if (! $this->authorization->scheduleIdsWritable($request, $scheduleIds)) {
            throw new ScheduleResetRejected(403, ['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE]);
        }

        $splits = ScheduleSplit::withTrashed()->whereIn('schedule_id', $scheduleIds)->orderBy('id')
            ->when($locked, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'deleted_at']);

        $assigned = $rows->filter(static fn (Schedule $row): bool => $row->faculty_id !== null)->values();
        $assigned->loadMissing(['course.department', 'section']);
        $crossDepartment = $assigned->filter(static fn (Schedule $row): bool => $row->course?->teaching_department_id !== null
            && (int) $row->course->teaching_department_id !== (int) $row->department_id)->values();

        $fingerprint = [
            'semester' => $semesterId,
            'sections' => $sectionIds,
            'schedules' => $rows->map(static fn (Schedule $row): string => $row->id.':'.($row->faculty_id ?? '-'))->all(),
            'splits' => $splits->modelKeys(),
        ];

        return [
            'semester' => $semester,
            'sections' => $sections,
            'section_ids' => $sectionIds,
            'schedules' => $rows,
            'split_ids' => array_map('intval', $splits->modelKeys()),
            'archived_split_ids' => array_map('intval', $splits->whereNotNull('deleted_at')->modelKeys()),
            'assigned' => $assigned,
            'cross_department_released' => $crossDepartment,
            'token' => hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Judges every selected section on its own: its live rows must all be plotting-editable, and
     * it must not be `included` in an open submission. A recalled section's pivot row is
     * `withdrawn`, so it is not claimed even while its submission stays `partially_withdrawn` for
     * the sections still under review. Closed, returned, and withdrawn submissions never block.
     *
     * @param  Collection<int, Sections>  $sections
     * @param  Collection<int, Schedule>  $rows
     */
    private function rejectBlockedSections(int $semesterId, Collection $sections, Collection $rows): void
    {
        /** @var array<int, string> $reasons */
        $reasons = [];
        foreach ($rows->groupBy(static fn (Schedule $row): int => (int) $row->section_id) as $sectionId => $sectionRows) {
            $reason = self::statusBlockReason($sectionRows->pluck('status')->all());
            if ($reason !== null) {
                $reasons[(int) $sectionId] = $reason;
            }
        }

        DB::table('schedule_submission_sections')
            ->join('schedule_submissions', 'schedule_submissions.id', '=', 'schedule_submission_sections.schedule_submission_id')
            ->where('schedule_submissions.semester_id', $semesterId)
            ->whereIn('schedule_submissions.status', self::OPEN_SUBMISSION_STATUSES)
            ->where('schedule_submission_sections.state', 'included')
            ->whereIn('schedule_submission_sections.section_id', $sections->modelKeys())
            ->orderBy('schedule_submissions.id')
            ->get(['schedule_submission_sections.section_id', 'schedule_submissions.status'])
            ->each(static function (object $claim) use (&$reasons): void {
                $reasons[(int) $claim->section_id] ??= match ($claim->status) {
                    'pending_dean' => 'is part of a submission waiting for Dean review',
                    'pending_vpaa' => 'is part of a submission waiting for VPAA review',
                    'approved' => 'is part of a VPAA-approved submission',
                    default => 'is still under review in a partially recalled submission',
                };
            });

        if ($reasons === []) {
            return;
        }

        $blocked = $sections->filter(static fn (Sections $section): bool => isset($reasons[(int) $section->id]))
            ->map(static fn (Sections $section): array => [
                'section_id' => (int) $section->id,
                'section_name' => $section->section_name,
                'reason' => $reasons[(int) $section->id],
            ])->values();
        $details = $blocked->take(5)->map(static fn (array $entry): string => "Section {$entry['section_name']} {$entry['reason']}")->implode('; ')
            .($blocked->count() > 5 ? '; and '.($blocked->count() - 5).' more' : '');

        throw new ScheduleResetRejected(422, [
            'message' => "Nothing was deleted. {$details}. Deselect ".($blocked->count() === 1 ? 'it' : 'them').' and try again.',
            'code' => 'reset_protected',
            'section_ids' => $blocked->pluck('section_id')->all(),
            'blocked_sections' => $blocked->all(),
        ]);
    }

    /**
     * Why a section's live statuses keep it out of Reset, or null when every row is editable.
     * The most protected stage wins, so a section is never described as merely returned while
     * another of its classes is approved or under review.
     *
     * @param  list<string>  $statuses
     */
    public static function statusBlockReason(array $statuses): ?string
    {
        $locked = array_values(array_diff($statuses, self::ELIGIBLE_STATUSES));
        if ($locked === []) {
            return null;
        }
        $has = static fn (array $set): bool => array_intersect($locked, $set) !== [];

        return match (true) {
            $has(SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES) => 'is approved by the VPAA and locked for instructor assignment',
            $has(['approved_by_dean', 'conditionally_approved']) => 'is waiting for VPAA review',
            $has(['submitted']) => 'is waiting for Dean review',
            default => 'has classes at a stage that cannot be reset',
        };
    }

    /** @return array<string, int> */
    private function counts(array $plan): array
    {
        $classKey = static fn (Schedule $row): string => $row->section_id.':'.$row->course_id;

        return [
            'schedules' => $plan['schedules']->count(),
            'classes' => $plan['schedules']->unique($classKey)->count(),
            'meeting_splits' => count($plan['split_ids']),
            'archived_meeting_splits' => count($plan['archived_split_ids']),
            'instructor_assignments' => $plan['assigned']->unique($classKey)->count(),
            'cross_department_instructor_assignments' => $plan['cross_department_released']->unique($classKey)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function describe(array $plan): array
    {
        $bySection = $plan['schedules']->groupBy('section_id');
        $semester = $plan['semester'];

        return [
            'semester' => [
                'id' => (int) $semester->id,
                'academic_year' => $semester->academic_year,
                'semester' => $semester->semester,
            ],
            'sections' => $plan['sections']->map(fn (Sections $section): array => [
                'id' => (int) $section->id,
                'name' => $section->section_name,
                'year_level' => $section->year_level,
                'schedules' => $bySection->get($section->id, collect())->count(),
            ])->values()->all(),
            'counts' => $this->counts($plan),
            'token' => $plan['token'],
        ];
    }

    /** @param list<int|string> $ids */
    private function sectionNames(Collection $sections, array $ids): string
    {
        $ids = array_map('intval', $ids);
        $names = $sections->filter(fn (Sections $section): bool => in_array((int) $section->id, $ids, true))
            ->pluck('section_name')->values();

        return ($names->count() === 1 ? 'Section ' : 'Sections ').$names->take(5)->implode(', ')
            .($names->count() > 5 ? ' and '.($names->count() - 5).' more' : '');
    }
}
