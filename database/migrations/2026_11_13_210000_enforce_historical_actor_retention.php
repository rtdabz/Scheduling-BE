<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const IMMUTABLE = ['authentication_audit_logs', 'scheduling_audit_logs', 'schedule_history_versions', 'schedule_history_items'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Historical retention requires a supported database trigger implementation.');
        }
        if (DB::table('institution_settings')->count() > 1
            || DB::table('users')->where('role', 'vpaa')->where('is_active', true)->whereNull('deleted_at')->count() > 1) {
            throw new RuntimeException('Duplicate institution settings or active VPAA accounts require review. No identities were merged or removed.');
        }

        // Restrict account deletion and retained approval/history parents. Normal soft archives still work.
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $keys = array_filter(Schema::getForeignKeys($name), static fn (array $key): bool => $key['foreign_table'] === 'users'
                || $name === 'scheduling_audit_logs'
                || in_array($name, ['schedule_submissions', 'schedule_history_versions', 'schedule_history_items', 'room_requests', 'room_recovery_plans'], true)
                || (in_array($name, ['scheduling_audit_logs', 'schedule_submission_sections'], true)
                    && in_array($key['foreign_table'], ['schedule_submissions', 'schedule_history_versions', 'departments', 'semesters'], true)));
            if ($keys === []) {
                continue;
            }
            Schema::table($name, function (Blueprint $blueprint) use ($keys, $name): void {
                foreach ($keys as $key) {
                    $blueprint->dropForeign($key['name'] ?? $key['columns']);
                    // Live sections/recommendations may be removed. Keep their recorded IDs intact,
                    // rather than allowing SET NULL cascades to rewrite immutable audit events.
                    if ($name === 'scheduling_audit_logs' && ! in_array($key['foreign_table'], ['users', 'schedule_submissions', 'schedule_history_versions', 'departments', 'semesters'], true)) {
                        continue;
                    }
                    $blueprint->foreign($key['columns'], $key['name'])
                        ->references($key['foreign_columns'])->on($key['foreign_table'])
                        ->restrictOnDelete()->restrictOnUpdate();
                }
            });
        }

        Schema::table('institution_settings', fn (Blueprint $table) => $table->unsignedTinyInteger('singleton_key')->virtualAs('1')->unique('institution_settings_singleton_unique'));
        Schema::table('users', fn (Blueprint $table) => $table->unsignedTinyInteger('active_vpaa_slot')->nullable()
            ->virtualAs("CASE WHEN role = 'vpaa' AND is_active = 1 AND deleted_at IS NULL THEN 1 ELSE NULL END")
            ->unique('users_active_vpaa_unique'));

        foreach (self::IMMUTABLE as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'retain_'.$table.'_'.strtolower($operation);
                $body = $driver === 'sqlite'
                    ? "BEGIN SELECT RAISE(ABORT, 'Historical events are append-only'); END"
                    : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical events are append-only'";
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}");
            }
        }
        // Submission/room states can progress, but the history containers themselves cannot disappear.
        foreach (['schedule_submissions', 'room_requests'] as $table) {
            $body = $driver === 'sqlite'
                ? "BEGIN SELECT RAISE(ABORT, 'Approval records cannot be deleted'); END"
                : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approval records cannot be deleted'";
            DB::unprepared("CREATE TRIGGER retain_{$table}_delete BEFORE DELETE ON {$table} FOR EACH ROW {$body}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Historical retention safeguards cannot be removed by rolling back application migrations.');
    }
};
