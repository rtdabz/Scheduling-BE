<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use Illuminate\Support\Collection;

/** Records one immutable history version for a single user operation. */
class ScheduleHistoryRecorder
{
    public function record(
        string $action,
        Collection|array $before,
        Collection|array $after,
        ?int $actorUserId,
        ?int $termId = null,
        ?int $departmentId = null,
        ?string $source = null,
        ?string $reason = null,
        array $changeSummary = [],
    ): ScheduleHistoryVersion {
        $beforeRows = $this->keyById($before);
        $afterRows = $this->keyById($after);
        if ($beforeRows->isEmpty() && $afterRows->isNotEmpty()) {
            // Bulk workflow callers run after the UPDATE. Recover the latest
            // durable snapshot for each schedule as the before-state when one
            // exists; first-ever transitions correctly remain null.
            $previous = ScheduleHistoryItem::query()
                ->whereIn('original_schedule_id', $afterRows->keys())
                ->orderByDesc('id')
                ->get()
                ->unique('original_schedule_id')
                ->keyBy('original_schedule_id');
            $beforeRows = $afterRows->mapWithKeys(function ($row, $id) use ($previous): array {
                $item = $previous->get($id);
                return [$id => $item?->after_snapshot ?: null];
            })->filter();
        }
        $ids = $beforeRows->keys()->merge($afterRows->keys())->unique()->values();

        $first = $afterRows->first() ?: $beforeRows->first();
        $termId ??= $this->value($first, 'term_id');
        $departmentId ??= $this->value($first, 'department_id');

        $version = ScheduleHistoryVersion::create([
            'term_id' => $termId,
            'department_id' => $departmentId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'source' => $source,
            'reason' => $reason,
            'change_summary' => $changeSummary + [
                'before_count' => $beforeRows->count(),
                'after_count' => $afterRows->count(),
                'affected_count' => $ids->count(),
            ],
        ]);

        foreach ($ids as $id) {
            $beforeRow = $beforeRows->get($id);
            $afterRow = $afterRows->get($id);
            $snapshot = $afterRow ?: $beforeRow;
            ScheduleHistoryItem::create([
                'history_version_id' => $version->id,
                'original_schedule_id' => $id ?: null,
                'section_id' => $this->value($snapshot, 'section_id'),
                'course_id' => $this->value($snapshot, 'course_id'),
                'faculty_id' => $this->value($snapshot, 'faculty_id'),
                'room_id' => $this->value($snapshot, 'room_id'),
                'before_snapshot' => $this->snapshot($beforeRow),
                'after_snapshot' => $this->snapshot($afterRow),
                'snapshot_metadata' => ['action' => $action, 'source' => $source],
            ]);
        }

        return $version;
    }

    private function keyById(Collection|array $rows): Collection
    {
        return collect($rows)->mapWithKeys(function ($row, $key): array {
            $id = $this->value($row, 'id') ?: $key;
            return [(int) $id => $row];
        })->filter(fn ($row, $id) => $id > 0);
    }

    private function value($row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row?->{$key} ?? null);
    }

    private function snapshot($row): ?array
    {
        if ($row === null) {
            return null;
        }
        return $row instanceof Schedule ? $row->getAttributes() : (array) $row;
    }
}
