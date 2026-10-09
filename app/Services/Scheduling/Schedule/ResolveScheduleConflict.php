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

final class ResolveScheduleConflict
{
    public const ACTIONS = [
        'move_schedule',
        'change_room',
        'change_delivery_mode',
        'reassign_instructor',
    ];

    /**
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
     * @param  array<string, mixed>  $action  validated payload: action, schedule_id, the action's fields, reason
     * @return array<string, mixed>
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

            $assignmentGroup = $instructorOnly
                ? $this->hybridAssignments->resolve($target)
                : collect([$target]);
            $assignmentGroupIds = $assignmentGroup->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            $partners = $instructorOnly
                ? collect()
                : $this->sameTimePartners->partnersFor($target, $changes);
            $runPartnerIds = $this->sameTimePartners->runPartnerIds($target, $partners);

            $attempt = array_merge($target->toArray(), $changes, [
                'ignore_schedule_id' => $instructorOnly
                    ? $assignmentGroupIds
                    : ($runPartnerIds === [] ? (int) $target->id : [(int) $target->id, ...$runPartnerIds]),
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
                'source' => ($action['source'] ?? null) === 'recommendation' ? 'recommendation' : 'manual',
            ], $reason, $actorUserId, 'conflict_resolved');
        });
    }

    /**
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

        $summary = [
            ...$summary,
            'conflict_message' => $case->message(),
            'conflict_day' => $case->day,
            'conflict_overlap_start' => $case->overlapStart,
            'conflict_overlap_end' => $case->overlapEnd,
            ...$case->owners(),
        ];

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
            'status' => 'resolved',
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
