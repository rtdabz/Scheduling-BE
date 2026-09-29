<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\SchedulingAuditLog;

/**
 * The Resolved side of the conflict inbox, read back from the audit trail.
 *
 * Nothing new is stored to make this list. Every way a conflict can end
 * already writes a `scheduling_audit_logs` row in the same transaction as the
 * change: `conflict_resolved` and `conflict_overridden` from the inbox, and
 * `schedule_plan_committed` (with `resolved_conflicts`) when an accepted
 * recommendation replaces the clashing rows, and `schedule_conflicts_cleared`
 * when a class is moved by hand in the Schedule Builder. This only reads those
 * rows back.
 *
 * A resolution is history, not a status. The open list stays the only truth
 * about what is broken now, so an entry whose conflict a fresh scan finds
 * again is reported as `reopened` rather than quietly still "resolved".
 */
final class ConflictResolutionLog
{
    /** Newest first; past this the list is an archive, not an inbox tab. */
    public const LIMIT = 200;

    /**
     * @param  list<string>  $openConflictIds  ids a fresh scan still finds
     * @return list<array<string, mixed>>
     */
    public function entries(int $semesterId, ?int $departmentId, ?int $sectionId, array $openConflictIds): array
    {
        $open = array_flip($openConflictIds);
        $entries = [];

        $logs = SchedulingAuditLog::query()
            ->with('user:id,name')
            ->where('semester_id', $semesterId)
            ->where(static function ($query): void {
                $query->whereIn('action', ['conflict_resolved', 'conflict_overridden'])
                    // Most plan commits clear nothing; only the ones that did
                    // belong here, or they would crowd the limit out.
                    ->orWhere(static fn ($commits) => $commits
                        ->whereIn('action', ['schedule_plan_committed', 'schedule_conflicts_cleared'])
                        ->whereJsonLength('metadata->resolved_conflicts', '>', 0));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        foreach ($logs as $log) {
            foreach ($this->conflictsOf($log) as $conflict) {
                if (! $this->inScope($conflict, $log, $departmentId, $sectionId)) {
                    continue;
                }

                $overridden = $log->action === 'conflict_overridden';
                $entries[] = [
                    'key' => $log->id.':'.$conflict['id'],
                    'conflict_id' => $conflict['id'],
                    'rule' => $conflict['rule'],
                    'message' => $conflict['message'],
                    'day' => $conflict['day'],
                    'overlap_start' => $conflict['overlap_start'],
                    'overlap_end' => $conflict['overlap_end'],
                    'method' => $overridden ? 'overridden' : $conflict['method'],
                    'source' => match ($log->action) {
                        'schedule_plan_committed' => 'schedule_generator',
                        'schedule_conflicts_cleared' => 'schedule_builder',
                        default => 'conflict_inbox',
                    },
                    'status' => isset($open[$conflict['id']]) ? 'reopened' : ($overridden ? 'overridden' : 'resolved'),
                    'resolved_at' => $log->created_at?->toISOString(),
                    'resolved_by' => $log->user?->name,
                    'reason' => $log->metadata['reason'] ?? null,
                    'affected_schedule_ids' => $conflict['affected_schedule_ids'],
                ];

                if (count($entries) >= self::LIMIT) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    /**
     * One audit row names one conflict from the inbox, or several from a plan
     * commit. Normalised to the same shape either way.
     *
     * @return list<array<string, mixed>>
     */
    private function conflictsOf(SchedulingAuditLog $log): array
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];

        if (in_array($log->action, ['schedule_plan_committed', 'schedule_conflicts_cleared'], true)) {
            $manual = $log->action === 'schedule_conflicts_cleared';

            return array_values(array_map(static fn (array $conflict): array => [
                'id' => (string) ($conflict['id'] ?? ''),
                'rule' => (string) ($conflict['rule'] ?? ''),
                'message' => (string) ($conflict['message'] ?? ''),
                'day' => $conflict['day'] ?? null,
                'overlap_start' => $conflict['overlap_start'] ?? null,
                'overlap_end' => $conflict['overlap_end'] ?? null,
                'department_ids' => $conflict['department_ids'] ?? null,
                'section_ids' => $conflict['section_ids'] ?? null,
                'method' => $manual ? 'manual' : 'recommended',
                'affected_schedule_ids' => array_map(
                    'intval',
                    $metadata[$manual ? 'saved_schedule_ids' : 'created_schedule_ids'] ?? [],
                ),
            ], array_filter($metadata['resolved_conflicts'] ?? [], 'is_array')));
        }

        $conflictId = (string) ($metadata['conflict_id'] ?? '');
        if ($conflictId === '') {
            return [];
        }

        return [[
            'id' => $conflictId,
            'rule' => (string) ($metadata['conflict_rule'] ?? ''),
            // Rows written before the message was recorded fall back to the
            // client's label for the rule.
            'message' => (string) ($metadata['conflict_message'] ?? ''),
            'day' => $metadata['conflict_day'] ?? null,
            'overlap_start' => $metadata['conflict_overlap_start'] ?? null,
            'overlap_end' => $metadata['conflict_overlap_end'] ?? null,
            'department_ids' => $metadata['department_ids'] ?? null,
            'section_ids' => $metadata['section_ids'] ?? null,
            'method' => ($metadata['source'] ?? null) === 'recommendation' ? 'recommended' : 'manual',
            'affected_schedule_ids' => array_map('intval', $metadata['affected_schedule_ids'] ?? []),
        ]];
    }

    /**
     * Same reach as the open list: a conflict belongs to both sides. Rows
     * recorded before owners were stored fall back to the log's own columns.
     *
     * @param  array<string, mixed>  $conflict
     */
    private function inScope(array $conflict, SchedulingAuditLog $log, ?int $departmentId, ?int $sectionId): bool
    {
        if ($departmentId !== null) {
            $departments = is_array($conflict['department_ids'])
                ? array_map('intval', $conflict['department_ids'])
                : [(int) $log->department_id];
            if (! in_array($departmentId, $departments, true)) {
                return false;
            }
        }

        if ($sectionId !== null) {
            $sections = is_array($conflict['section_ids'])
                ? array_map('intval', $conflict['section_ids'])
                : [(int) $log->section_id];
            if (! in_array($sectionId, $sections, true)) {
                return false;
            }
        }

        return true;
    }
}
