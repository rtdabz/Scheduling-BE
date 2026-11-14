<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $different = static fn (string $old, string $new): string => $sqlite ? "{$old} IS NOT {$new}" : "NOT ({$old} <=> {$new})";
        $guards = [
            'schedules' => [$different('OLD.created_by', 'NEW.created_by'), $different('OLD.creator_identity', 'NEW.creator_identity')],
            'system_notifications' => [$different('OLD.actor_id', 'NEW.actor_id'), $different("json_extract(OLD.metadata, '$._actor')", "json_extract(NEW.metadata, '$._actor')"), $different("json_extract(OLD.metadata, '$._actor_type')", "json_extract(NEW.metadata, '$._actor_type')")],
        ];
        foreach ([
            'schedule_submissions' => ['submitted_by' => 'submitted_at', 'dean_reviewed_by' => 'dean_reviewed_at', 'vpaa_reviewed_by' => 'vpaa_reviewed_at'],
            'room_requests' => ['reviewed_by' => 'reviewed_at'],
            'room_recovery_plans' => ['dean_reviewed_by' => 'dean_reviewed_at', 'reviewed_by' => 'reviewed_at'],
        ] as $table => $slots) {
            foreach ($slots as $actor => $time) {
                $guards[$table][] = "(OLD.{$time} IS NOT NULL AND (".$different("OLD.{$actor}", "NEW.{$actor}").' OR '.$different("OLD.{$time}", "NEW.{$time}").'))';
            }
        }
        foreach (['prepared', 'reviewed', 'recommended', 'approved'] as $slot) {
            $oldSlot = "json_extract(OLD.signatories, '$.{$slot}')";
            $newSlot = "json_extract(NEW.signatories, '$.{$slot}')";
            $recorded = $slot === 'prepared' ? "{$oldSlot} IS NOT NULL"
                : ($sqlite ? "json_extract(OLD.signatories, '$.{$slot}.acted_at') IS NOT NULL"
                    : "json_unquote(json_extract(OLD.signatories, '$.{$slot}.acted_at')) NOT IN ('null', '')");
            $guards['schedule_submissions'][] = "({$recorded} AND ".$different($oldSlot, $newSlot).')';
        }
        $guards['room_recovery_plans'][] = "(OLD.status <> 'preview' AND NEW.status = 'preview')";
        $oldSignatories = "json_extract(OLD.actor_snapshots, '$.signatories')";
        $newSignatories = "json_extract(NEW.actor_snapshots, '$.signatories')";
        $frozen = $sqlite ? "json_extract(OLD.actor_snapshots, '$.signatories.frozen') = 1" : "json_unquote(json_extract(OLD.actor_snapshots, '$.signatories.frozen')) = 'true'";
        $guards['room_recovery_plans'][] = "({$frozen} AND ".$different($oldSignatories, $newSignatories).')';

        foreach ($guards as $table => $conditions) {
            $condition = implode(' OR ', $conditions);
            $body = $sqlite
                ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Historical identity evidence cannot be replaced'); END"
                : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical identity evidence cannot be replaced'; END IF; END";
            DB::unprepared("CREATE TRIGGER retain_{$table}_evidence BEFORE UPDATE ON {$table} FOR EACH ROW {$body}");
        }

        // Revocation/cancellation append decisions; they cannot erase or replace an earlier approval.
        $old = "json_extract(OLD.actor_snapshots, '$.decisions')";
        $new = "json_extract(NEW.actor_snapshots, '$.decisions')";
        if ($sqlite) {
            $body = "WHEN EXISTS (SELECT 1 FROM json_each({$old}) prior WHERE NOT EXISTS (SELECT 1 FROM json_each({$new}) current WHERE prior.key = current.key AND prior.value IS current.value)) BEGIN SELECT RAISE(ABORT, 'Room decisions are append-only'); END";
        } else {
            $body = "BEGIN DECLARE decision_index INT DEFAULT 0; WHILE decision_index < COALESCE(json_length({$old}), 0) DO IF NOT (json_extract({$old}, CONCAT('$[', decision_index, ']')) <=> json_extract({$new}, CONCAT('$[', decision_index, ']'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room decisions are append-only'; END IF; SET decision_index = decision_index + 1; END WHILE; END";
        }
        DB::unprepared("CREATE TRIGGER retain_room_request_decisions BEFORE UPDATE ON room_requests FOR EACH ROW {$body}");
    }

    public function down(): void
    {
        throw new RuntimeException('Historical evidence safeguards cannot be removed through rollback.');
    }
};
