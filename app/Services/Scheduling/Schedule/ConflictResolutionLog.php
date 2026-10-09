<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use Illuminate\Support\Collection;

final class ConflictResolutionLog
{
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
                    ->orWhere(static fn ($commits) => $commits
                        ->whereIn('action', ['schedule_plan_committed', 'schedule_conflicts_cleared'])
                        ->whereJsonLength('metadata->resolved_conflicts', '>', 0));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $names = $this->namesFor($logs);

        foreach ($logs as $log) {
            $fix = $this->fixFor($log, $names);
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
                    'fix' => $fix,
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
     * @param  Collection<int, SchedulingAuditLog>  $logs
     * @return array{classes: array<int, string>, faculty: array<int, string>, rooms: array<int, string>}
     */
    private function namesFor(Collection $logs): array
    {
        $scheduleIds = [];
        $facultyIds = [];
        $roomIds = [];
        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            $changes = is_array($metadata['changes'] ?? null) ? $metadata['changes'] : [];
            if (isset($metadata['schedule_id'])) {
                $scheduleIds[] = (int) $metadata['schedule_id'];
            }
            if (isset($changes['faculty_id'])) {
                $facultyIds[] = (int) $changes['faculty_id'];
            }
            if (isset($changes['room_id'])) {
                $roomIds[] = (int) $changes['room_id'];
            }
        }

        $classes = $scheduleIds === [] ? [] : Schedule::withTrashed()
            ->with(['course:id,course_code', 'section:id,section_name'])
            ->whereIn('id', array_unique($scheduleIds))
            ->get(['id', 'course_id', 'section_id'])
            ->mapWithKeys(static fn (Schedule $schedule): array => [(int) $schedule->id => trim(
                ($schedule->course?->course_code ?? 'the class')
                .($schedule->section?->section_name ? " ({$schedule->section->section_name})" : ''),
            )])
            ->all();

        $faculty = $facultyIds === [] ? [] : Faculty::withTrashed()
            ->whereIn('id', array_unique($facultyIds))
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(static fn (Faculty $person): array => [(int) $person->id => trim("{$person->first_name} {$person->last_name}")])
            ->all();

        $rooms = $roomIds === [] ? [] : Rooms::query()
            ->whereIn('id', array_unique($roomIds))
            ->pluck('room_code', 'id')
            ->mapWithKeys(static fn ($code, $id): array => [(int) $id => (string) $code])
            ->all();

        return ['classes' => $classes, 'faculty' => $faculty, 'rooms' => $rooms];
    }

    /**
     * @param  array{classes: array<int, string>, faculty: array<int, string>, rooms: array<int, string>}  $names
     */
    private function fixFor(SchedulingAuditLog $log, array $names): ?string
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];

        if ($log->action === 'conflict_overridden') {
            return 'Kept both classes as they are (allowed to stand).';
        }
        if ($log->action === 'schedule_conflicts_cleared') {
            return 'A class was moved or edited in the Schedule Builder.';
        }
        if ($log->action === 'schedule_plan_committed') {
            return 'The timetable was regenerated without the clash.';
        }

        $changes = is_array($metadata['changes'] ?? null) ? $metadata['changes'] : [];
        $class = $names['classes'][(int) ($metadata['schedule_id'] ?? 0)] ?? 'the class';

        return match ($metadata['action'] ?? null) {
            'reassign_instructor' => isset($changes['faculty_id'])
                ? 'Assigned '.($names['faculty'][(int) $changes['faculty_id']] ?? 'another instructor')." to {$class}."
                : "Removed the instructor from {$class}.",
            'change_room' => "Moved {$class} to ".(isset($changes['room_id'])
                ? ($names['rooms'][(int) $changes['room_id']] ?? 'another room')
                : 'no room').'.',
            'change_delivery_mode' => "Changed {$class} to ".($changes['mode'] ?? 'another delivery mode').'.',
            'move_schedule' => "Moved {$class} to ".trim(implode(' ', array_filter([
                $changes['day'] ?? null,
                isset($changes['start_time'], $changes['end_time'])
                    ? ScheduleConflictCase::clock((string) $changes['start_time']).' - '.ScheduleConflictCase::clock((string) $changes['end_time'])
                    : null,
                isset($changes['room_id']) ? 'in '.($names['rooms'][(int) $changes['room_id']] ?? 'another room') : null,
            ]))).'.',
            default => null,
        };
    }

    /**
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
