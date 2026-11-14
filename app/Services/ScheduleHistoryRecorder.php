<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use Illuminate\Support\Collection;

class ScheduleHistoryRecorder
{
    public function record(
        string $action,
        Collection|array $before,
        Collection|array $after,
        ?int $actorUserId,
        ?int $semesterId = null,
        ?int $departmentId = null,
        ?string $source = null,
        ?string $reason = null,
        array $changeSummary = [],
        array $itemMetadata = [],
        ?array $signatories = null,
    ): ScheduleHistoryVersion {
        $beforeRows = $this->keyById($before);
        $afterRows = $this->keyById($after);
        if ($beforeRows->isEmpty() && $afterRows->isNotEmpty()) {
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
        $semesterId ??= $this->value($first, 'semester_id');
        $departmentId ??= $this->value($first, 'department_id');

        $version = ScheduleHistoryVersion::create([
            'semester_id' => $semesterId,
            'department_id' => $departmentId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'source' => $source,
            'signatories' => $signatories,
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
                'before_snapshot' => $this->snapshot($beforeRow),
                'after_snapshot' => $this->snapshot($afterRow),
                'snapshot_metadata' => ($itemMetadata[$id] ?? []) + ['action' => $action, 'source' => $source],
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
        if (! $row instanceof Schedule) {
            return (array) $row;
        }

        // Split metadata lives on schedule_splits; keep it in the snapshot (as the semester archiver
        // does) so a lecture/laboratory pairing can be rendered and reused from history alone.
        return $row->getAttributes() + [
            'split_group_id' => $row->split_group_id,
            'meeting_type' => $row->meeting_type,
            'meeting_index' => $row->meeting_index,
        ];
    }
}
