<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Upgrade installations that already applied an earlier revision of the retention guards. */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite rebuilds tables to change FKs; retain all unrelated native guards across the rebuild.
        $sqliteTriggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', ['scheduling_audit_logs', 'room_recovery_plans'])->get(['name', 'sql'])
            : collect();

        foreach (['scheduling_audit_logs', 'room_recovery_plans'] as $name) {
            $keys = array_filter(Schema::getForeignKeys($name), static function (array $key) use ($name): bool {
                return $name === 'scheduling_audit_logs'
                    ? ! in_array($key['foreign_table'], ['users', 'schedule_submissions', 'schedule_history_versions', 'departments', 'semesters'], true)
                    : strtolower($key['on_delete']) !== 'restrict' || strtolower($key['on_update']) !== 'restrict';
            });
            if ($keys === []) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($keys, $name): void {
                foreach ($keys as $key) {
                    $table->dropForeign($key['name'] ?? $key['columns']);
                    if ($name === 'room_recovery_plans') {
                        $table->foreign($key['columns'], $key['name'])->references($key['foreign_columns'])->on($key['foreign_table'])
                            ->restrictOnDelete()->restrictOnUpdate();
                    }
                }
            });
        }
        foreach ($sqliteTriggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }

        foreach (['retain_schedules_evidence', 'retain_system_notifications_evidence', 'retain_schedule_submissions_evidence',
            'retain_room_requests_evidence', 'retain_room_recovery_plans_evidence', 'retain_room_request_decisions'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        $completedEvidence = require __DIR__.'/2026_11_14_000000_preserve_completed_identity_evidence.php';
        $completedEvidence->up();
    }

    public function down(): void
    {
        throw new RuntimeException('Historical identity guards cannot be removed through rollback.');
    }
};
