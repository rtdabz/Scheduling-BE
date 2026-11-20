<?php

namespace App\Services\Scheduling\History;

use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Per-version facts the Schedule History list shows: class count, distinct section count, first class and
 * first section name. Computing them reads every item's JSON (hundreds of rows per semester archive, slow on
 * large tables), but history rows are append-only (DB triggers forbid UPDATE/DELETE), so each settled
 * version's summary is computed once and cached forever.
 */
class ScheduleHistorySummaries
{
    /** A version's items are inserted right after it; older versions can no longer change. */
    public const SETTLE_SECONDS = 60;

    /**
     * @param  array<int, int>  $versionIds
     * @param  array<int, int>  $cacheable  versions old enough that no more items can be added
     * @return array<int, array{count: int, sections: int, schedule_id: mixed, snapshot: ?array, section_name: mixed}>
     */
    public function forVersions(array $versionIds, array $cacheable): array
    {
        $summaries = [];
        $missing = [];
        foreach ($versionIds as $id) {
            $cached = Cache::get(self::key($id));
            is_array($cached) ? $summaries[$id] = $cached : $missing[] = $id;
        }
        if ($missing === []) {
            return $summaries;
        }

        $items = $this->summaryItems(ScheduleHistoryItem::query()->whereIn('history_version_id', $missing))->get()->groupBy('history_version_id');
        $firstIds = $items->map(fn ($rows) => $rows->first()->id)->values();
        $firstSnapshots = ScheduleHistoryItem::query()->whereIn('id', $firstIds)->get(['id', 'after_snapshot', 'before_snapshot'])->keyBy('id');
        foreach ($missing as $id) {
            $rows = $items->get($id, collect());
            $first = $rows->first();
            $summaries[$id] = [
                'count' => $rows->count(),
                'sections' => $rows
                    ->map(fn (ScheduleHistoryItem $item) => $this->sectionId($item))
                    ->filter(fn ($section) => $section !== null && $section !== '')
                    ->map(fn ($section) => (string) $section)
                    ->unique()->count(),
                'schedule_id' => $first?->original_schedule_id,
                'snapshot' => $first ? self::snapshotOf($firstSnapshots->get($first->id)) : null,
                'section_name' => $first ? $this->firstOf($first, 'summary_section_name', fn () => data_get($first->snapshot_metadata, 'section_name')) : null,
            ];
            if (in_array($id, $cacheable, true)) {
                Cache::forever(self::key($id), $summaries[$id]);
            }
        }

        return $summaries;
    }

    /** Compute and cache summaries for every settled version not yet cached. Returns how many were added. */
    public function warm(int $chunk = 50): int
    {
        $warmed = 0;
        ScheduleHistoryVersion::query()
            ->where('created_at', '<', now()->subSeconds(self::SETTLE_SECONDS))
            ->select('id')
            ->chunkById($chunk, function ($versions) use (&$warmed): void {
                $ids = array_values(array_filter($versions->pluck('id')->all(), fn (int $id) => ! Cache::has(self::key($id))));
                if ($ids !== []) {
                    $this->forVersions($ids, $ids);
                    $warmed += count($ids);
                }
            });

        return $warmed;
    }

    public static function key(int $versionId): string
    {
        return 'schedule-history:summary:v1:'.$versionId;
    }

    public static function snapshotOf(?ScheduleHistoryItem $item): ?array
    {
        $snapshot = $item?->after_snapshot ?: $item?->before_snapshot;

        return is_array($snapshot) ? $snapshot : null;
    }

    private function sectionId(ScheduleHistoryItem $item): mixed
    {
        return $this->firstOf($item, 'summary_section_id', fn () => data_get(self::snapshotOf($item), 'section_id'))
            ?? $this->firstOf($item, 'summary_metadata_section_id', fn () => data_get($item->snapshot_metadata, 'section_id'));
    }

    /** Read a SQL-extracted alias; on drivers without JSON functions the full row was loaded instead. */
    private function firstOf(ScheduleHistoryItem $item, string $alias, callable $fallback): mixed
    {
        if (! array_key_exists($alias, $item->getAttributes())) {
            return $fallback();
        }
        $value = $item->getAttributes()[$alias];

        // JSON_UNQUOTE yields the string "null" for a JSON null; treat it as absent.
        return is_string($value) && strcasecmp($value, 'null') === 0 ? null : $value;
    }

    /** Select only the few JSON fields needed instead of whole snapshots. */
    private function summaryItems(Builder $items): Builder
    {
        $extract = match ($items->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => fn (string $column, string $path) => "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))",
            'sqlite' => fn (string $column, string $path) => "CASE WHEN json_valid({$column}) THEN json_extract({$column}, '{$path}') END",
            default => null,
        };
        $items->select(['id', 'history_version_id', 'original_schedule_id'])->orderBy('id');
        if ($extract === null) {
            return $items->addSelect(['after_snapshot', 'before_snapshot', 'snapshot_metadata']);
        }

        return $items
            ->selectRaw($extract('COALESCE(after_snapshot, before_snapshot)', '$.section_id').' as summary_section_id')
            ->selectRaw($extract('snapshot_metadata', '$.section_id').' as summary_metadata_section_id')
            ->selectRaw($extract('snapshot_metadata', '$.section_name').' as summary_section_name');
    }
}
