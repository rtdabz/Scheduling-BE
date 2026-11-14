<?php

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Support\ArchiveAuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only inventory of archived (soft-deleted) schedules and meeting splits that earlier Reset
 * actions may have created. It deletes nothing: every query runs inside a transaction that is
 * always rolled back, and Schedule History is only read to report references.
 *
 * Classification needs record-level evidence. A generic batch-deletion event or a timestamp alone
 * never confirms a reset: only the "reset" cross-department release notice naming the schedule
 * does. Explicit Delete/Archive events mark intentional archives. Everything else is uncertain.
 */
class ResetArchiveInventoryCommand extends Command
{
    protected $signature = 'schedules:reset-archive-inventory {--output= : JSON file to write (default: storage/app/reset-archive-inventory.json)}';

    protected $description = 'Read-only inventory of archived schedules and splits possibly created by earlier Reset actions';

    private const WINDOW_SECONDS = 120;

    public function handle(): int
    {
        DB::beginTransaction();
        try {
            $report = $this->inventory();
        } finally {
            DB::rollBack();
        }

        $path = $this->option('output') ?: storage_path('app/reset-archive-inventory.json');
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->table(['Classification', 'Schedules', 'Splits'], collect(['confirmed_reset', 'intentionally_archived', 'uncertain'])->map(fn (string $class): array => [
            $class,
            collect($report['schedules'])->where('classification', $class)->count(),
            collect($report['splits'])->where('classification', $class)->count(),
        ])->all());
        $this->line('Proposed deletion (confirmed only): '.count($report['proposed_deletion']['schedule_ids']).' schedules, '.count($report['proposed_deletion']['split_ids']).' splits.');
        $this->line("Inventory written to {$path}. Nothing was changed.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function inventory(): array
    {
        $archived = Schedule::onlyTrashed()->orderBy('id')->get();
        $historyRefs = DB::table('schedule_history_items')
            ->whereIn('original_schedule_id', $archived->modelKeys())
            ->selectRaw('original_schedule_id, COUNT(*) AS aggregate')
            ->groupBy('original_schedule_id')
            ->pluck('aggregate', 'original_schedule_id');
        $openSections = DB::table('schedule_submission_sections')
            ->join('schedule_submissions', 'schedule_submissions.id', '=', 'schedule_submission_sections.schedule_submission_id')
            ->whereIn('schedule_submissions.status', ['pending_dean', 'pending_vpaa', 'approved', 'partially_withdrawn'])
            ->where('schedule_submission_sections.state', 'included')
            ->get(['schedule_submission_sections.section_id', 'schedule_submissions.semester_id'])
            ->map(fn ($row): string => $row->semester_id.':'.$row->section_id)
            ->flip();
        $activeSemesters = DB::table('semesters')->where('is_active', true)->pluck('id')->map('intval')->all();

        $schedules = $archived->map(function (Schedule $schedule) use ($historyRefs, $openSections, $activeSemesters): array {
            [$classification, $evidence] = $this->classify($schedule);

            return [
                'id' => (int) $schedule->id,
                'semester_id' => (int) $schedule->semester_id,
                'section_id' => (int) $schedule->section_id,
                'course_id' => (int) $schedule->course_id,
                'department_id' => (int) $schedule->department_id,
                'status' => $schedule->status,
                'deleted_at' => $schedule->deleted_at?->toIso8601String(),
                'classification' => $classification,
                'evidence' => $evidence,
                'protected_dependencies' => array_values(array_filter([
                    isset($historyRefs[$schedule->id]) ? "schedule_history_items reference it ({$historyRefs[$schedule->id]}); history is never deleted or edited" : null,
                    $schedule->status !== 'draft' ? "archived at workflow status '{$schedule->status}'" : null,
                    isset($openSections[$schedule->semester_id.':'.$schedule->section_id]) ? 'its section is in an open submission' : null,
                    ! in_array((int) $schedule->semester_id, $activeSemesters, true) ? 'belongs to an inactive semester' : null,
                ])),
            ];
        })->values();

        $byId = $schedules->keyBy('id');
        $splits = ScheduleSplit::onlyTrashed()->orderBy('id')->get()->map(function (ScheduleSplit $split) use ($byId): array {
            $parent = $byId->get((int) $split->schedule_id);
            $parentLive = $parent === null && Schedule::query()->whereKey($split->schedule_id)->exists();

            return [
                'id' => (int) $split->id,
                'schedule_id' => (int) $split->schedule_id,
                'split_group_id' => $split->split_group_id,
                'deleted_at' => $split->deleted_at?->toIso8601String(),
                'classification' => $parent['classification'] ?? 'uncertain',
                'evidence' => $parent !== null
                    ? ['follows its archived class #'.$split->schedule_id]
                    : [$parentLive ? 'retired split metadata on a live class (pattern change or recovery), not a reset' : 'parent class not found'],
            ];
        })->values();

        $confirmedIds = $schedules->where('classification', 'confirmed_reset')->pluck('id')->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'rules' => [
                'confirmed_reset' => 'A cross_department_instructors_released notice whose message says "reset its schedule" names this schedule, within '.self::WINDOW_SECONDS.'s of its archive time.',
                'intentionally_archived' => 'A schedule_deleted (explicit Delete) or record_archived event names this schedule.',
                'uncertain' => 'Anything else, including batch deletions without reset-specific evidence and generation replacements.',
            ],
            'schedules' => $schedules->all(),
            'splits' => $splits->all(),
            'proposed_deletion' => [
                'note' => 'Proposal only. Requires separate approval of this concrete list. Schedule History records are never included.',
                'schedule_ids' => $confirmedIds,
                'split_ids' => $splits->where('classification', 'confirmed_reset')->pluck('id')->values()->all(),
            ],
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    private function classify(Schedule $schedule): array
    {
        $at = $schedule->deleted_at instanceof Carbon ? $schedule->deleted_at : Carbon::parse($schedule->deleted_at);
        $window = [$at->copy()->subSeconds(self::WINDOW_SECONDS), $at->copy()->addSeconds(self::WINDOW_SECONDS)];
        $names = static fn ($metadata, int $id): bool => (int) ($metadata['schedule_id'] ?? 0) === $id
            || in_array($id, array_map('intval', (array) ($metadata['schedule_ids'] ?? [])), true);

        $logs = SchedulingAuditLog::query()
            ->whereBetween('created_at', $window)
            ->whereIn('action', ['schedule_deleted', 'schedule_batch_deleted', ArchiveAuditRecorder::ACTION, 'schedule_created'])
            ->orderBy('id')
            ->get();

        $explicit = $logs->first(fn (SchedulingAuditLog $log): bool => ($log->action === 'schedule_deleted' && $names($log->metadata ?? [], (int) $schedule->id))
            || ($log->action === ArchiveAuditRecorder::ACTION && ($log->metadata['type'] ?? null) === 'schedules' && (int) ($log->metadata['id'] ?? 0) === (int) $schedule->id));
        if ($explicit !== null) {
            return ['intentionally_archived', ["{$explicit->action} event #{$explicit->id}"]];
        }

        $batch = $logs->first(fn (SchedulingAuditLog $log): bool => $log->action === 'schedule_batch_deleted' && $names($log->metadata ?? [], (int) $schedule->id));
        $resetNotice = DB::table('system_notifications')
            ->where('type', 'cross_department_instructors_released')
            ->whereBetween('created_at', $window)
            ->where('message', 'like', '%reset its schedule%')
            ->get(['id', 'metadata'])
            ->first(fn ($row): bool => $names(json_decode((string) $row->metadata, true) ?? [], (int) $schedule->id));
        if ($resetNotice !== null) {
            return ['confirmed_reset', ["reset release notice #{$resetNotice->id}", ...($batch ? ["schedule_batch_deleted event #{$batch->id}"] : [])]];
        }

        if ($batch === null) {
            return ['uncertain', ['no deletion event names this schedule']];
        }
        $generated = $logs->contains(fn (SchedulingAuditLog $log): bool => $log->action === 'schedule_created'
            && (int) $log->user_id === (int) $batch->user_id
            && (int) $log->semester_id === (int) $batch->semester_id
            && abs($log->created_at->diffInSeconds($batch->created_at)) <= 5);

        return ['uncertain', [
            "schedule_batch_deleted event #{$batch->id}",
            $generated ? 'schedules were created in the same batch (generation replacement, not a reset)' : 'no schedules created in the same batch (consistent with a reset, but not proof)',
        ]];
    }
}
