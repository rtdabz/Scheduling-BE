<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        foreach ([
            'schedule_submissions' => ['submitted_by' => 'submitted_at', 'dean_reviewed_by' => 'dean_reviewed_at', 'vpaa_reviewed_by' => 'vpaa_reviewed_at'],
            'room_requests' => ['requested_by' => null, 'reviewed_by' => 'reviewed_at'],
            'room_recovery_plans' => ['created_by' => null, 'dean_reviewed_by' => 'dean_reviewed_at', 'reviewed_by' => 'reviewed_at'],
        ] as $table => $actors) {
            $changed = [];
            foreach ($actors as $column => $timeColumn) {
                $difference = $sqlite ? "OLD.{$column} IS NOT NEW.{$column}" : "NOT (OLD.{$column} <=> NEW.{$column})";
                if ($timeColumn) {
                    $difference .= $sqlite ? " OR OLD.{$timeColumn} IS NOT NEW.{$timeColumn}" : " OR NOT (OLD.{$timeColumn} <=> NEW.{$timeColumn})";
                }
                $changed[] = "(OLD.{$column} IS NOT NULL AND ({$difference}))";
                $old = "json_extract(OLD.actor_snapshots, '$.{$column}')";
                $new = "json_extract(NEW.actor_snapshots, '$.{$column}')";
                $changed[] = $sqlite ? "({$old} IS NOT NULL AND {$old} IS NOT {$new})" : "({$old} IS NOT NULL AND NOT ({$old} <=> {$new}))";
            }
            if ($table === 'schedule_submissions') {
                $changed[] = $sqlite
                    ? "(json_extract(OLD.signatories, '$.frozen') = 1 AND OLD.signatories IS NOT NEW.signatories)"
                    : "(json_unquote(json_extract(OLD.signatories, '$.frozen')) = 'true' AND NOT (OLD.signatories <=> NEW.signatories))";
            }
            $condition = implode(' OR ', $changed);
            $body = $sqlite
                ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Recorded approval identities cannot be replaced'); END"
                : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recorded approval identities cannot be replaced'; END IF; END";
            DB::unprepared("CREATE TRIGGER retain_{$table}_actors BEFORE UPDATE ON {$table} FOR EACH ROW {$body}");
        }

        $condition = $sqlite ? "OLD.status <> 'preview'" : "OLD.status <> 'preview'";
        $body = $sqlite
            ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Submitted transfers cannot be deleted'); END"
            : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted transfers cannot be deleted'; END IF; END";
        DB::unprepared("CREATE TRIGGER retain_submitted_room_transfers BEFORE DELETE ON room_recovery_plans FOR EACH ROW {$body}");
    }

    public function down(): void
    {
        throw new RuntimeException('Recorded approval identities cannot be made mutable through rollback.');
    }
};
