<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $actors = ['schedule_generation_runs' => 'requested_by', 'room_recovery_cases' => 'created_by', 'curriculum_imports' => 'user_id'];
        foreach ($actors as $table => $actor) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json('actor_snapshots')->nullable());
        }
        // Import confirmation is retained evidence; master-record deletion must not erase or relink it.
        $keys = Schema::getForeignKeys('curriculum_imports');
        Schema::table('curriculum_imports', function (Blueprint $table) use ($keys): void {
            foreach ($keys as $key) {
                $table->dropForeign($key['name'] ?? $key['columns']);
                $table->foreign($key['columns'], $key['name'])->references($key['foreign_columns'])->on($key['foreign_table'])
                    ->restrictOnDelete()->restrictOnUpdate();
            }
        });

        $sqlite = DB::getDriverName() === 'sqlite';
        $different = static fn (string $old, string $new): string => $sqlite ? "{$old} IS NOT {$new}" : "NOT ({$old} <=> {$new})";
        foreach ($actors as $table => $actor) {
            $conditions = [$different("OLD.{$actor}", "NEW.{$actor}"), $different('OLD.actor_snapshots', 'NEW.actor_snapshots')];
            if ($table === 'curriculum_imports') {
                $conditions[] = $different('OLD.record', 'NEW.record');
                $conditions[] = $different("json_extract(OLD.summary, '$.confirmation')", "json_extract(NEW.summary, '$.confirmation')");
            }
            $condition = implode(' OR ', $conditions);
            $body = $sqlite
                ? "WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Recorded workflow origin cannot be replaced'); END"
                : "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recorded workflow origin cannot be replaced'; END IF; END";
            DB::unprepared("CREATE TRIGGER retain_{$table}_origin BEFORE UPDATE ON {$table} FOR EACH ROW {$body}");
        }
        $body = $sqlite ? "BEGIN SELECT RAISE(ABORT, 'Import confirmations cannot be deleted'); END"
            : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Import confirmations cannot be deleted'";
        DB::unprepared("CREATE TRIGGER retain_curriculum_imports_delete BEFORE DELETE ON curriculum_imports FOR EACH ROW {$body}");
    }

    public function down(): void
    {
        throw new RuntimeException('Recorded origin evidence cannot be discarded by rollback.');
    }
};
