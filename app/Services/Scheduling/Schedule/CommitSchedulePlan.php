<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Exceptions\ScheduleConflictException;
use App\Exceptions\SchedulePlanCommitException;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleCandidate;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\SchedulePlanStatus;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
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
    ) {}

    /**
     * Commit a final plan against the exact scheduling state from which it was generated.
     *
     * Authorization remains the responsibility of the eventual HTTP adapter.
     */
    public function commit(SchedulePlan $plan, ?int $actorUserId = null): SchedulePlan
    {
        [$semesterId, $departmentId] = $this->assertCommitEligible($plan);

        try {
            $committed = $this->lock->execute([$semesterId], function () use ($plan, $actorUserId, $semesterId, $departmentId): SchedulePlan {
                return DB::transaction(function () use ($plan, $actorUserId, $semesterId, $departmentId): SchedulePlan {
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

                    foreach ($before as $schedule) {
                        $schedule->delete();
                    }

                    $createdIds = [];
                    foreach ($plan->rows as $row) {
                        $schedule = Schedule::create($this->persistencePayload($row, $snapshot));
                        $createdIds[] = (int) $schedule->id;
                    }

                    $created = Schedule::query()
                        ->whereIn('id', $createdIds)
                        ->orderBy('id')
                        ->get();

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
                            'replaced_schedule_ids' => array_map('intval', $before->modelKeys()),
                            'created_schedule_ids' => $createdIds,
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
                            'replaced_schedule_ids' => array_map('intval', $before->modelKeys()),
                            'created_schedule_ids' => $createdIds,
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
