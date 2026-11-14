<?php

namespace App\Services\Scheduling\History;

use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSplit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The history versions that record an officially approved department schedule.
 *
 * Two events qualify, and nothing else does:
 *  - `schedule_approved_by_vpaa` (source `department_workflow`): the final approval itself.
 *  - `schedule_semester_archived` (source `semester_change`): the end-of-semester snapshot, which
 *    only ever contains rows in a VPAA-approved status (see ScheduleSemesterArchiver).
 *
 * Dean decisions, returns, recalls and pending submissions never reach final approval, so they are
 * excluded from Schedule History, JSON export and reuse.
 */
final class ApprovedScheduleHistory
{
    public const VPAA_APPROVED = 'schedule_approved_by_vpaa';

    public const SEMESTER_SNAPSHOT = 'schedule_semester_archived';

    /** Filter keys accepted by GET /schedule-history?type= */
    public const TYPES = [
        'approved' => [self::VPAA_APPROVED],
        'archived' => [self::SEMESTER_SNAPSHOT],
    ];

    /** @param  list<string>|null  $actions */
    public static function query(?array $actions = null): Builder
    {
        $actions ??= [self::VPAA_APPROVED, self::SEMESTER_SNAPSHOT];

        return ScheduleHistoryVersion::query()
            ->whereIn('action', $actions)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $approval) => $approval->where('source', 'department_workflow')->where('action', self::VPAA_APPROVED))
                ->orWhere(fn (Builder $archive) => $archive->where('source', 'semester_change')->where('action', self::SEMESTER_SNAPSHOT)));
    }

    /** The VPAA reads every department; everyone else reads only their own. */
    public static function visibleTo(Builder $query, ?User $user): Builder
    {
        return $user?->role === 'vpaa'
            ? $query
            : $query->where('department_id', $user?->department_id ?? 0);
    }

    public static function find(int $versionId, ?User $user): ?ScheduleHistoryVersion
    {
        return self::visibleTo(self::query(), $user)
            ->with(['items', 'department', 'semesterRecord'])
            ->find($versionId);
    }

    /** Snapshot rows recorded by the version; legacy approval rows are enriched with live split data. */
    public static function rows(ScheduleHistoryVersion $version): Collection
    {
        $items = $version->items->sortBy('id')->values();
        $missingSplit = $items
            ->filter(fn ($item) => ! array_key_exists('split_group_id', (array) ($item->after_snapshot ?: $item->before_snapshot)))
            ->pluck('original_schedule_id')->filter()->all();
        $splits = $missingSplit === []
            ? collect()
            : ScheduleSplit::withTrashed()->whereIn('schedule_id', $missingSplit)->get()->keyBy('schedule_id');

        return $items->map(function ($item) use ($splits): array {
            $snapshot = (array) ($item->after_snapshot ?: $item->before_snapshot);
            if (! array_key_exists('split_group_id', $snapshot) && ($split = $splits->get($item->original_schedule_id)) !== null) {
                $snapshot += [
                    'split_group_id' => $split->split_group_id,
                    'meeting_type' => $split->meeting_type,
                    'meeting_index' => $split->meeting_index,
                ];
            }

            return [
                'item_id' => (int) $item->id,
                'original_schedule_id' => $item->original_schedule_id === null ? null : (int) $item->original_schedule_id,
                'snapshot' => $snapshot,
                'metadata' => (array) ($item->snapshot_metadata ?? []),
            ];
        })->values();
    }

    public static function academicYear(ScheduleHistoryVersion $version): ?string
    {
        return $version->academic_year ?? $version->semesterRecord?->academic_year;
    }

    public static function term(ScheduleHistoryVersion $version): ?string
    {
        return $version->semester ?? $version->semesterRecord?->semester;
    }
}
