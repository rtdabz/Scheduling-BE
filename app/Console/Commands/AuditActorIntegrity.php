<?php

namespace App\Console\Commands;

use App\Models\AuthenticationAuditLog;
use App\Support\ActorIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Read-only inventory: never infers or rewrites an identity from the current account. */
class AuditActorIntegrity extends Command
{
    protected $signature = 'integrity:audit-actors {--output= : Write the findings as JSON to this local path}';

    protected $description = 'Inventory missing actor evidence and chronologically inconsistent historical signatories without changing records';

    public function handle(): int
    {
        $findings = [];
        foreach (['scheduling_audit_logs' => ['user_id', 'metadata'], 'authentication_audit_logs' => ['actor_user_id', 'metadata'], 'schedule_history_versions' => ['actor_user_id', 'change_summary']] as $table => [$actorColumn, $metadataColumn]) {
            foreach (DB::table($table)->orderBy('id')->lazyById(500) as $row) {
                $metadata = json_decode($row->{$metadataColumn} ?? 'null', true);
                $actor = $table === 'authentication_audit_logs'
                    ? ActorIdentity::authentication((new AuthenticationAuditLog)->newFromBuilder((array) $row))
                    : ActorIdentity::recorded($metadata, $row->{$actorColumn});
                if (! ($actor['name'] ?? null) && ($metadata['_actor_type'] ?? null) !== 'system') {
                    $findings[] = ['issue' => 'actor_identity_unavailable', 'table' => $table, 'id' => $row->id, 'actor_id' => $row->{$actorColumn}];
                }
            }
        }
        foreach (DB::table('schedule_history_versions')->orderBy('id')->lazyById(500) as $version) {
            $signatories = json_decode($version->signatories ?? 'null', true);
            if (! $signatories && $version->action === 'schedule_semester_archived') {
                $findings[] = ['issue' => 'semester_signatories_missing', 'table' => 'schedule_history_versions', 'id' => $version->id];
            }
            foreach (['reviewed', 'recommended', 'approved'] as $slot) {
                $actedAt = $signatories[$slot]['acted_at'] ?? null;
                // Event timestamps have whole-second precision; fractional stamps in that second are consistent.
                if ($actedAt && CarbonImmutable::parse($actedAt)->getTimestamp() > CarbonImmutable::parse($version->created_at, config('app.timezone'))->getTimestamp()) {
                    $findings[] = ['issue' => 'signatory_after_history_event', 'table' => 'schedule_history_versions', 'id' => $version->id, 'slot' => $slot, 'acted_at' => $actedAt, 'event_at' => $version->created_at];
                }
            }
        }
        foreach (['schedule_submissions', 'room_requests', 'room_recovery_plans', 'schedule_generation_runs', 'room_recovery_cases', 'curriculum_imports'] as $table) {
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                if (! json_decode($row->actor_snapshots ?? 'null', true)) {
                    $findings[] = ['issue' => 'workflow_actor_snapshots_missing', 'table' => $table, 'id' => $row->id];
                }
            }
        }
        $report = ['generated_at' => now()->toIso8601String(), 'database' => DB::connection()->getDatabaseName(),
            'policy' => 'Read-only evidence inventory. Missing identities require independent contemporaneous evidence; current accounts are not proof.',
            'counts' => collect($findings)->countBy('issue')->all(), 'findings' => $findings];
        if ($path = $this->option('output')) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        $this->table(['Finding', 'Count'], collect($report['counts'])->map(fn ($count, $issue) => [$issue, $count])->values());
        $this->info('Read-only inventory complete; no database records were changed.');

        return self::SUCCESS;
    }
}
