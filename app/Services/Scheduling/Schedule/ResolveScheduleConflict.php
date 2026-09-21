<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Exceptions\ConflictResolutionException;
use App\Exceptions\ScheduleConflictException;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Services\ScheduleHistoryRecorder;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use App\Support\ApiCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a chosen fix for a detected conflict into one transactional write.
 *
 * The shape is deliberately the same as CommitSchedulePlan's: take the
 * scheduling scope lock, reload the affected rows inside the transaction,
 * apply the change, revalidate against what is now persisted, and refuse the
 * whole thing if anything hard remains. Nothing here decides what a conflict
 * *is* -- RuleEngine judges the changed row against persisted rows and
 * ScheduleConflictScanner re-derives the conflict set -- so there is no second
 * conflict engine to keep in step with the first.
 *
 * A conflict is resolved only when a fresh scan no longer produces its id. The
 * client asking for a resolution is a request, not an outcome, and nothing is
 * written to `schedules.status` to mark one: that field is an operational
 * workflow status, not a conflict lifecycle.
 */
final class ResolveScheduleConflict
{
    /** Manual actions this service applies. */
    public const ACTIONS = [
        'move_schedule',
        'change_room',
        'change_delivery_mode',
        'reassign_instructor',
    ];

    /**
     * Which schedule columns each action may write. Anything else in the
     * payload is a caller mistake, not an edit.
     *
     * @var array<string, list<string>>
     */
    public const ACTION_FIELDS = [
        'move_schedule' => ['day', 'start_time', 'end_time', 'room_id', 'mode'],
        'change_room' => ['room_id', 'mode'],
        'change_delivery_mode' => ['mode', 'room_id'],
        'reassign_instructor' => ['faculty_id'],
    ];

    public function __construct(
        private readonly SchedulingScopeLock $lock,
        private readonly ScheduleConflictScanner $scanner,
        private readonly RuleEngine $ruleEngine,
        private readonly SameTimePartnerMover $sameTimePartners,
        private readonly ManualHybridFacultyAssignmentResolver $hybridAssignments,
        private readonly ScheduleHistoryRecorder $historyRecorder,
    ) {}

    /**
     * Apply a manual fix and prove the conflict is gone.
     *
     * @param  array<string, mixed>  $action  validated payload: action, schedule_id, the action's fields, reason
     * @return array<string, mixed>
     *
     * @throws ConflictResolutionException|ScheduleConflictException
     */
    public function resolve(string $conflictId, array $action, ?int $actorUserId = null): array
    {
        $name = (string) ($action['action'] ?? '');
        if (! in_array($name, self::ACTIONS, true)) {
            throw new ConflictResolutionException("`{$name}` is not a supported resolution action.");
        }

        $targetId = (int) ($action['schedule_id'] ?? 0);
        $reason = $this->reason($action);

        return $this->inScope($conflictId, function (ScheduleConflictCase $case, array $before) use ($name, $targetId, $action, $reason, $actorUserId, $conflictId): array {
            if (! $case->involves($targetId)) {
                throw new ConflictResolutionException(
                    'The schedule being changed is not part of this conflict.',
                );
            }

            $target = $this->lockRow($targetId);
            $changes = $this->changesFor($name, $action);
            $instructorOnly = $name === 'reassign_instructor';

            if (! $instructorOnly && ! in_array($target->status, SameTimePartnerMover::EDITABLE_STATUSES, true)) {
                throw new ConflictResolutionException(
                    'This class is locked at its current approval stage. Recall it or return it to revision before resolving the conflict here.',
                );
            }

            // Assignment follows the whole hybrid/component group, exactly as the
            // manual assignment path does, so a pair cannot end up half reassigned.
            $assignmentGroup = $instructorOnly
                ? $this->hybridAssignments->resolve($target)
                : collect([$target]);
            $assignmentGroupIds = $assignmentGroup->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            $attempt = array_merge($target->toArray(), $changes, [
                'ignore_schedule_id' => $instructorOnly ? $assignmentGroupIds : (int) $target->id,
            ]);

            $violations = $instructorOnly
                ? $this->ruleEngine->validateInstructorAssignment($attempt)
                : $this->ruleEngine->validate($attempt);

            if ($violations !== []) {
                throw new ScheduleConflictException(
                    $violations,
                    'That change does not resolve the conflict.',
                );
            }

            $partners = $instructorOnly
                ? collect()
                : $this->sameTimePartners->partnersFor($target, $changes);
            $beforeRows = $this->snapshotRows([
                ...$assignmentGroupIds,
                ...$partners->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            ]);

            $movedPartnerIds = $partners->isNotEmpty()
                ? $this->sameTimePartners->move($attempt, $partners)
                : [];

            $target->update($changes);

            if ($instructorOnly) {
                $this->assignGroup($assignmentGroup, $target, $changes['faculty_id'], $assignmentGroupIds);
            }

            $affectedIds = array_values(array_unique([
                (int) $target->id,
                ...$assignmentGroupIds,
                ...$movedPartnerIds,
            ]));

            return $this->settle($case, $before, $affectedIds, $beforeRows, $conflictId, [
                'action' => $name,
                'schedule_id' => (int) $target->id,
                'changes' => $changes,
            ], $reason, $actorUserId, 'conflict_resolved');
        });
    }

    /**
     * Let a permitted instructor clash stand on purpose, with a reason.
     *
     * The override itself is the existing one: FacultyConflictOverride flags
     * both meetings, and the flag stands only while each keeps the instructor,
     * day and time it was approved with. This adds the conflict-inbox entry
     * point and the audit trail.
     *
     * @return array<string, mixed>
     *
     * @throws ConflictResolutionException
     */
    public function override(string $conflictId, string $reason, ?int $actorUserId = null): array
    {
        if (trim($reason) === '') {
            throw new ConflictResolutionException('An override needs a reason.');
        }

        return $this->inScope($conflictId, function (ScheduleConflictCase $case, array $before) use ($reason, $actorUserId, $conflictId): array {
            if (! in_array($case->rule, FacultyConflictOverride::RULES, true)) {
                throw new ConflictResolutionException(
                    'Only an instructor conflict can be allowed to stand. This one has to be resolved.',
                );
            }

            $ids = $case->scheduleIds();
            $beforeRows = $this->snapshotRows($ids);
            FacultyConflictOverride::flag($ids);

            return $this->settle($case, $before, $ids, $beforeRows, $conflictId, [
                'action' => 'override',
                'overridden_schedule_ids' => $ids,
            ], $reason, $actorUserId, 'conflict_overridden');
        });
    }

    /**
     * Locate the conflict, take the semester's scheduling scope lock, and run
     * the change in one transaction against a scan taken inside it.
     *
     * @param  callable(ScheduleConflictCase, list<ScheduleConflictCase>): array<string, mixed>  $apply
     * @return array<string, mixed>
     */
    private function inScope(string $conflictId, callable $apply): array
    {
        $parsed = ScheduleConflictCase::parseId($conflictId);
        if ($parsed === null) {
            throw new ConflictResolutionException('That is not a conflict identifier.', 404);
        }

        $semesterId = (int) Schedule::query()
            ->whereIn('id', [$parsed['schedule_id'], $parsed['other_schedule_id']])
            ->value('semester_id');

        if ($semesterId <= 0) {
            throw new ConflictResolutionException('This conflict no longer exists.', 404);
        }

        $result = $this->lock->execute([$semesterId], function () use ($conflictId, $semesterId, $apply): array {
            return DB::transaction(function () use ($conflictId, $semesterId, $apply): array {
                // Re-derived inside the lock and the transaction: a resolution
                // decided against the list the user was looking at would act on
                // a conflict someone else may already have fixed or moved.
                $before = $this->scanner->scan($semesterId);
                $case = $this->find($before, $conflictId);

                if ($case === null) {
                    throw new ConflictResolutionException(
                        'This conflict is already resolved.',
                        409,
                        ['conflicts' => array_map(
                            static fn (ScheduleConflictCase $open): array => $open->toArray(),
                            $before,
                        )],
                    );
                }

                return $apply($case, $before);
            });
        });

        ApiCache::forgetGroups(['instructor_assignments.index', 'faculty.index', 'initial.data']);

        return $result;
    }

    /**
     * Re-scan, refuse anything still or newly broken, and record what happened.
     *
     * @param  list<ScheduleConflictCase>  $before
     * @param  list<int>  $affectedIds
     * @param  Collection<int, Schedule>  $beforeRows
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function settle(
        ScheduleConflictCase $case,
        array $before,
        array $affectedIds,
        $beforeRows,
        string $conflictId,
        array $summary,
        ?string $reason,
        ?int $actorUserId,
        string $auditAction,
    ): array {
        $after = $this->scanner->scan($case->semesterId);

        if (ScheduleConflictScanner::contains($after, $conflictId)) {
            throw new ScheduleConflictException(
                [$this->violationFor($case)],
                'The conflict is still there after that change, so nothing was saved.',
            );
        }

        // Only conflicts this change created, and only on the rows it touched.
        // A pre-existing clash elsewhere in the semester is not this edit's to
        // answer for, and blocking on it would make some conflicts unfixable.
        $introduced = array_values(array_filter(
            ScheduleConflictScanner::introduced($before, $after),
            static function (ScheduleConflictCase $open) use ($affectedIds): bool {
                foreach ($affectedIds as $id) {
                    if ($open->involves($id)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        if ($introduced !== []) {
            throw new ScheduleConflictException(
                array_map(fn (ScheduleConflictCase $open): array => $this->violationFor($open), $introduced),
                'That change would create a new conflict, so nothing was saved.',
            );
        }

        $afterRows = Schedule::query()->whereIn('id', $affectedIds)->orderBy('id')->get();
        $first = $afterRows->first();

        $version = $this->historyRecorder->record(
            $auditAction,
            $beforeRows,
            $afterRows,
            $actorUserId,
            $case->semesterId,
            $first !== null ? (int) $first->department_id : null,
            'conflict_resolution',
            $reason,
            [
                'conflict_id' => $conflictId,
                'conflict_rule' => $case->rule,
                'affected_schedule_ids' => $affectedIds,
                ...$summary,
            ],
        );

        SchedulingAuditLog::create([
            'user_id' => $actorUserId,
            'history_version_id' => $version->id,
            'semester_id' => $case->semesterId,
            'section_id' => $first !== null ? (int) $first->section_id : null,
            'department_id' => $first !== null ? (int) $first->department_id : null,
            'action' => $auditAction,
            'metadata' => [
                'conflict_id' => $conflictId,
                'conflict_rule' => $case->rule,
                'affected_schedule_ids' => $affectedIds,
                'reason' => $reason,
                ...$summary,
            ],
            'created_at' => now(),
        ]);

        return [
            'conflict_id' => $conflictId,
            'status' => $auditAction === 'conflict_overridden' ? 'overridden' : 'resolved',
            'affected_schedule_ids' => $affectedIds,
            'history_version_id' => (int) $version->id,
            'semester_id' => $case->semesterId,
            'remaining_conflicts' => array_map(
                static fn (ScheduleConflictCase $open): array => $open->toArray(),
                $after,
            ),
        ];
    }

    /**
     * @param  Collection<int, Schedule>  $group
     * @param  list<int>  $groupIds
     */
    private function assignGroup($group, Schedule $target, ?int $facultyId, array $groupIds): void
    {
        foreach ($group as $related) {
            if ((int) $related->id === (int) $target->id) {
                continue;
            }

            $violations = $this->ruleEngine->validateInstructorAssignment([
                ...$related->toArray(),
                'faculty_id' => $facultyId,
                'ignore_schedule_id' => $groupIds,
            ]);

            if ($violations !== []) {
                throw new ScheduleConflictException(
                    $violations,
                    'The instructor cannot take the linked meeting of this class.',
                );
            }

            $related->update(['faculty_id' => $facultyId]);
        }
    }

    /**
     * @param  list<ScheduleConflictCase>  $cases
     */
    private function find(array $cases, string $conflictId): ?ScheduleConflictCase
    {
        foreach ($cases as $case) {
            if ($case->id() === $conflictId) {
                return $case;
            }
        }

        return null;
    }

    private function lockRow(int $scheduleId): Schedule
    {
        $schedule = Schedule::query()->whereKey($scheduleId)->lockForUpdate()->first();

        if ($schedule === null) {
            throw new ConflictResolutionException('That class no longer exists.', 404);
        }

        return $schedule;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Schedule>
     */
    private function snapshotRows(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === []
            ? collect()
            : Schedule::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function changesFor(string $name, array $action): array
    {
        $changes = [];
        foreach (self::ACTION_FIELDS[$name] as $field) {
            if (array_key_exists($field, $action)) {
                $changes[$field] = $action[$field];
            }
        }

        if ($name === 'reassign_instructor') {
            $changes['faculty_id'] = ($action['faculty_id'] ?? null) === null ? null : (int) $action['faculty_id'];

            return $changes;
        }

        // An online meeting holds no room, matching every other write path.
        if (($changes['mode'] ?? null) === 'online') {
            $changes['room_id'] = null;
        }

        if (array_key_exists('room_id', $changes) && $changes['room_id'] !== null) {
            $changes['room_id'] = (int) $changes['room_id'];
        }

        if ($changes === []) {
            throw new ConflictResolutionException("`{$name}` needs at least one change to apply.");
        }

        return $changes;
    }

    /** @param array<string, mixed> $action */
    private function reason(array $action): ?string
    {
        $reason = trim((string) ($action['reason'] ?? ''));

        return $reason === '' ? null : $reason;
    }

    /** @return array<string, mixed> */
    private function violationFor(ScheduleConflictCase $case): array
    {
        return [
            'rule' => $case->rule,
            'message' => $case->message(),
            'conflict_id' => $case->id(),
            'conflicting_schedule_ids' => $case->scheduleIds(),
            'day' => $case->day,
            'overlap_start' => $case->overlapStart,
            'overlap_end' => $case->overlapEnd,
        ];
    }
}
