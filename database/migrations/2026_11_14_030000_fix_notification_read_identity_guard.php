<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $conditions = [$sqlite ? 'OLD.actor_id IS NOT NEW.actor_id' : 'NOT (OLD.actor_id <=> NEW.actor_id)'];
        foreach (['_actor', '_actor_type'] as $key) {
            $old = "json_extract(OLD.metadata, '$.{$key}')";
            $new = "json_extract(NEW.metadata, '$.{$key}')";
            // MariaDB can compare unchanged OLD/NEW JSON strings as unequal inside a trigger.
            $conditions[] = $sqlite ? "{$old} IS NOT {$new}"
                : "NOT (CAST({$old} AS BINARY) <=> CAST({$new} AS BINARY))";
            if ($sqlite) {
                $conditions[] = "json_type(OLD.metadata, '$.{$key}') IS NOT json_type(NEW.metadata, '$.{$key}')";
            }
        }
        $condition = implode(' OR ', $conditions);
        $body = $sqlite
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Historical identity evidence cannot be replaced'); END"
            : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical identity evidence cannot be replaced'; END IF; END";

        DB::unprepared('DROP TRIGGER IF EXISTS retain_system_notifications_evidence');
        DB::unprepared("CREATE TRIGGER retain_system_notifications_evidence BEFORE UPDATE ON system_notifications FOR EACH ROW {$body}");
    }

    public function down(): void
    {
        throw new RuntimeException('Historical identity safeguards cannot be removed through rollback.');
    }
};
