<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Exceptions\ScheduleConflictException;
use App\Exceptions\SchedulePlanCommitException;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Submission\RevisionChangeRecorder;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleCandidate;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\SchedulePlanStatus;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\Families\InstructorAvailabilityConstraints;
use App\Services\Scheduling\Engine\Constraints\ValidateScheduleCandidate;
use App\Services\Scheduling\Generation\ValidateGenerationConfiguration;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use App\Support\ApiCache;
use Illuminate\Support\Facades\DB;

final class CommitSchedulePlan
{
    private const REPLACEABLE_STATUSES = ['draft', 'completed', 'revision'];

    public function __construct(
        private readonly SchedulingScopeLock $lock,
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly ValidateGenerationConfiguration $configurationValidator,
        private readonly ValidateScheduleCandidate $candidateValidator,
        private readonly ScheduleHistoryRecorder $historyRecorder,
        private readonly ScheduleConflictScanner $conflicts,
        private readonly RevisionChangeRecorder $revisionChanges,
    ) {}

    /**
     * Commit a final plan against the exact scheduling state from which it was generated.
     *
     * Authorization remains the responsibility of the eventual HTTP adapter.
     */
    public function commit(SchedulePlan $plan, ?int $actorUserId = null, bool $resetInstructors = false): SchedulePlan
    {
        [$semesterId, $departmentId] = $this->assertCommitEligible($plan);

        try {
            $committed = $this->lock->execute([$semesterId], function () use ($plan, $actorUserId, $semesterId, $departmentId, $resetInstructors): SchedulePlan {
                return DB::transaction(function () use ($plan, $actorUserId, $semesterId, $departmentId, $resetInstructors): SchedulePlan {
                    $snapshot = $this->snapshots->captureForConfiguration(
                        $semesterId,
                        $departmentId,
                        $plan->configuration,
                    );

                    if (! hash_equals($plan->snapshotFingerprint, $snapshot->fingerprint)) {
                        $this->reject([
                            $this->violation(
                                'stale_schedule_plan',
                                'The scheduling data changed after this plan was generated. Generate a fresh plan before saving.',
                                [
                                    'plan_fingerprint' => $plan->snapshotFingerprint,
                                    'current_fingerprint' => $snapshot->fingerprint,
                                ],
                            ),
                        ]);
                    }

                    $configurationValidation = $this->configurationValidator->validateSnapshot(
                        $plan->configuration,
                        $snapshot,
                    );
                    $candidateViolations = $this->candidateValidator->validate(
                        new ScheduleCandidate($plan->rows),
                        $plan->configuration,
                        $snapshot,
                    );
                    $hardViolations = array_values(array_filter(
                        [...$configurationValidation->violations, ...$candidateViolations],
                        static fn (ConstraintViolation $violation): bool => $violation->severity === 'hard',
                    ));

                    if ($hardViolations !== []) {
                        $this->reject($hardViolations, 'The schedule plan failed final validation and was not saved.');
                    }

                    $protectedCourseIds = collect($snapshot->persistedSchedules)
                        ->filter(static fn (array $schedule): bool => (int) ($schedule['section_id'] ?? 0) === $plan->configuration->sectionId
                            && in_array((int) ($schedule['course_id'] ?? 0), $plan->configuration->courseIds, true)
                            && ! in_array((string) ($schedule['status'] ?? ''), self::REPLACEABLE_STATUSES, true)
                        )
                        ->pluck('course_id')
                        ->map('intval')
                        ->unique()
                        ->values()
                        ->all();

                    if ($protectedCourseIds !== []) {
                        $this->reject([
                            $this->violation(
                                'duplicate_section_course',
                                'The section already has a protected schedule for a course in this plan.',
                                ['course_ids' => $protectedCourseIds],
                            ),
                        ]);
                    }

                    $before = Schedule::query()
                        ->where('semester_id', $semesterId)
                        ->where('section_id', $plan->configuration->sectionId)
                        ->whereIn('course_id', $plan->configuration->courseIds)
                        ->whereIn('status', self::REPLACEABLE_STATUSES)
                        ->orderBy('id')
                        ->get();

                    // What the replaced rows were clashing with, read before
                    // they go, so the commit can say which conflicts it cleared.
                    $replacedIds = array_map('intval', $before->modelKeys());
                    $openBefore = $replacedIds === []
                        ? []
                        : $this->conflicts->scan($semesterId, onlyScheduleIds: $replacedIds);

                    // Drafts that never entered approval are replaced outright.
                    // Archiving them filled the Archive page with rows that
                    // could be restored on top of the new timetable; the history
                    // snapshot below still records them.
                    if ($replacedIds !== []) {
                        ScheduleSplit::withTrashed()->whereIn('schedule_id', $replacedIds)->forceDelete();
                        Schedule::withTrashed()->whereIn('id', $replacedIds)->forceDelete();
                    }

                    $createdIds = [];
                    foreach ($plan->rows as $row) {
                        $schedule = Schedule::create($this->persistencePayload($row, $snapshot));
                        $createdIds[] = (int) $schedule->id;
                    }

                    // Keep the instructors the replaced rows had unless the
                    // caller asked for a reset; only a clash or an invalid
                    // assignment sends a meeting back for reassignment.
                    $needsReassignment = $resetInstructors
                        ? []
                        : $this->carryOverInstructors($before, $createdIds, $snapshot, $semesterId);

                    $created = Schedule::query()
                        ->whereIn('id', $createdIds)
                        ->orderBy('id')
                        ->get();

                    $resolvedConflicts = $openBefore === []
                        ? []
                        : array_map(
                            static fn (ScheduleConflictCase $case): array => $case->toResolutionRecord(),
                            ScheduleConflictScanner::cleared(
                                $openBefore,
                                $this->conflicts->scan($semesterId, onlyScheduleIds: $createdIds),
                                $replacedIds,
                            ),
                        );

                    // Regenerating a recalled or rejected section replaces its
                    // working copy; that version's history keeps what it held.
                    $this->revisionChanges->recordScheduleChanges($before, $created, $actorUserId, 'regenerate');

                    $version = $this->historyRecorder->record(
                        'schedule_plan_committed',
                        $before,
                        $created,
                        $actorUserId,
                        $semesterId,
                        $departmentId,
                        'schedule_plan_commit',
                        null,
                        [
                            'plan_id' => $plan->planId,
                            'snapshot_fingerprint' => $snapshot->fingerprint,
                            'replaced_schedule_ids' => $before->modelKeys(),
                            'created_schedule_ids' => $createdIds,
                            'resolved_conflicts' => $resolvedConflicts,
                        ],
                    );

                    SchedulingAuditLog::create([
                        'user_id' => $actorUserId,
                        'history_version_id' => $version->id,
                        'semester_id' => $semesterId,
                        'section_id' => $plan->configuration->sectionId,
                        'department_id' => $departmentId,
                        'action' => 'schedule_plan_committed',
                        'metadata' => [
                            'plan_id' => $plan->planId,
                            'snapshot_fingerprint' => $snapshot->fingerprint,
                            'replaced_schedule_ids' => $replacedIds,
                            'created_schedule_ids' => $createdIds,
                            'resolved_conflicts' => $resolvedConflicts,
                            'instructors_reset' => $resetInstructors,
                            'instructors_needing_reassignment' => $needsReassignment,
                        ],
                        'created_at' => now(),
                    ]);

                    return new SchedulePlan(
                        planId: $plan->planId,
                        configuration: $plan->configuration,
                        snapshotFingerprint: $snapshot->fingerprint,
                        status: SchedulePlanStatus::Committed,
                        rows: $plan->rows,
                        violations: $plan->violations,
                        recommendations: $plan->recommendations,
                        appliedAdjustments: $plan->appliedAdjustments,
                        unresolvedResources: [],
                        scores: $plan->scores,
                        metadata: [
                            ...$plan->metadata,
                            'committed_at' => now()->toISOString(),
                            'history_version_id' => (int) $version->id,
                            'replaced_schedule_ids' => $replacedIds,
                            'created_schedule_ids' => $createdIds,
                            'resolved_conflicts' => $resolvedConflicts,
                            'instructors_reset' => $resetInstructors,
                            'instructors_needing_reassignment' => $needsReassignment,
                        ],
                        schemaVersion: $plan->schemaVersion,
                    );
                });
            });
        } catch (ScheduleConflictException $exception) {
            throw new SchedulePlanCommitException(
                array_map(
                    static fn (array $violation): ConstraintViolation => ConstraintViolation::fromArray($violation),
                    $exception->violations(),
                ),
                $exception->getMessage(),
            );
        }

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return $committed;
    }

    /**
     * Re-attach each replaced meeting's instructor to the matching new meeting
     * (same course, meeting type and index; falling back to the course's single
     * instructor) and revalidate it against the new slot: instructor active,
     * part-time availability, and no overlap with the instructor's other classes.
     *
     * @param  \Illuminate\Support\Collection<int, Schedule>  $before
     * @param  list<int>  $createdIds
     * @return list<array<string, mixed>> meetings left without an instructor because the old one no longer fits
     */
    private function carryOverInstructors($before, array $createdIds, SchedulingSnapshot $snapshot, int $semesterId): array
    {
        $byMeeting = [];
        $byCourse = [];
        foreach ($before as $old) {
            if ($old->faculty_id === null) {
                continue;
            }
            $byMeeting[$old->course_id.'|'.$old->meeting_type.'|'.$old->meeting_index] ??= (int) $old->faculty_id;
            $byCourse[$old->course_id][(int) $old->faculty_id] = true;
        }

        if ($byMeeting === []) {
            return [];
        }

        $availability = new InstructorAvailabilityConstraints();
        $unassigned = [];

        foreach (Schedule::query()->whereIn('id', $createdIds)->orderBy('id')->get() as $new) {
            $facultyId = $byMeeting[$new->course_id.'|'.$new->meeting_type.'|'.$new->meeting_index]
                ?? (count($byCourse[$new->course_id] ?? []) === 1 ? array_key_first($byCourse[$new->course_id]) : null);
            if ($facultyId === null) {
                continue;
            }

            $reasons = array_map(
                static fn (ConstraintViolation $violation): string => $violation->ruleId,
                $availability->forRow(ScheduleRow::fromArray([...$new->toArray(), 'faculty_id' => $facultyId]), $snapshot),
            );
            if (! isset($snapshot->facultiesById[$facultyId])) {
                $reasons[] = 'faculty_missing';
            }

            $clash = Schedule::query()
                ->where('semester_id', $semesterId)
                ->where('faculty_id', $facultyId)
                ->where('day', $new->day)
                ->where('id', '!=', $new->id)
                ->where('start_time', '<', $new->end_time)
                ->where('end_time', '>', $new->start_time)
                ->exists();
            if ($clash) {
                $reasons[] = 'instructor_conflict';
            }

            if ($reasons !== []) {
                $unassigned[] = [
                    'schedule_id' => (int) $new->id,
                    'course_id' => (int) $new->course_id,
                    'faculty_id' => $facultyId,
                    'reasons' => $reasons,
                ];

                continue;
            }

            $new->update(['faculty_id' => $facultyId]);
        }

        return $unassigned;
    }

    /** @return array{int, int} */
    private function assertCommitEligible(SchedulePlan $plan): array
    {
        $violations = [];

        if ($plan->status !== SchedulePlanStatus::RoomAssignmentComplete
            || $plan->rows === []
            || $plan->hasHardViolations()
            || $plan->unresolvedResources !== []
            || $plan->requiresConfirmation()) {
            $violations[] = $this->violation(
                'plan_not_commit_ready',
                'Only a room-complete schedule plan with no blocking issues or pending confirmations can be committed.',
                ['status' => $plan->status->value],
            );
        }

        $first = $plan->rows[0] ?? null;
        $semesterId = $first?->semesterId ?? 0;
        $departmentId = $first?->departmentId ?? 0;
        $configuredCourseIds = $plan->configuration->courseIds;
        sort($configuredCourseIds);
        $rowCourseIds = array_values(array_unique(array_map(
            static fn (ScheduleRow $row): int => $row->courseId,
            $plan->rows,
        )));
        sort($rowCourseIds);

        foreach ($plan->rows as $index => $row) {
            if ($row->semesterId !== $semesterId
                || $row->departmentId !== $departmentId
                || $row->sectionId !== $plan->configuration->sectionId
                || ! in_array($row->courseId, $plan->configuration->courseIds, true)
                || $row->status !== 'draft') {
                $violations[] = $this->violation(
                    'schedule_plan_scope_mismatch',
                    'A schedule plan row falls outside the configured semester, department, section, course, or draft persistence scope.',
                    ['row_index' => $index],
                );
            }
        }

        if ($semesterId <= 0 || $departmentId <= 0 || $rowCourseIds !== $configuredCourseIds) {
            $violations[] = $this->violation(
                'schedule_plan_scope_mismatch',
                'The schedule plan does not represent every configured course in one valid scheduling scope.',
                [
                    'configuration_course_ids' => $configuredCourseIds,
                    'row_course_ids' => $rowCourseIds,
                ],
            );
        }

        if ($violations !== []) {
            $this->reject($violations);
        }

        return [$semesterId, $departmentId];
    }

    /** @return array<string, mixed> */
    private function persistencePayload(ScheduleRow $row, SchedulingSnapshot $snapshot): array
    {
        $room = $row->roomId === null ? null : ($snapshot->roomsById[$row->roomId] ?? null);
        $roomId = is_array($room) && ! (bool) ($room['virtual'] ?? false)
            ? $row->roomId
            : null;

        return [
            ...$row->toArray(),
            'faculty_id' => $row->facultyId,
            'faculty_assignment_done' => false,
            'room_id' => $row->mode === 'online' ? null : $roomId,
            // Record which curriculum produced this row. Deriving it later from
            // the section would misreport every timetable generated before the
            // section was moved to a different curriculum.
            'curriculum_id' => $snapshot->curriculumIdBySectionId[$row->sectionId] ?? null,
            'status' => 'draft',
        ];
    }

    /** @param list<ConstraintViolation> $violations */
    private function reject(array $violations, string $message = 'The schedule plan cannot be committed.'): never
    {
        throw new SchedulePlanCommitException($violations, $message);
    }

    /** @param array<string, mixed> $context */
    private function violation(string $ruleId, string $message, array $context = []): ConstraintViolation
    {
        return new ConstraintViolation(
            ruleId: $ruleId,
            message: $message,
            scope: 'schedule_plan_commit',
            context: $context,
        );
    }
}
