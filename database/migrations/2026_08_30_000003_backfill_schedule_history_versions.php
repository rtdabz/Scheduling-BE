<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('schedule_histories')) {
            return;
        }

        // The migration is safe to retry if a JSON binding or connection error
        // occurs after some legacy rows have already been copied.
        DB::table('schedule_history_versions')->where('source', 'legacy_backfill')->delete();

        DB::table('schedule_histories')
            ->orderBy('id')
            ->chunkById(200, function ($histories): void {
                foreach ($histories as $history) {
                    $snapshot = is_string($history->snapshot) ? json_decode($history->snapshot, true) : $history->snapshot;
                    $changes = is_string($history->changes) ? json_decode($history->changes, true) : $history->changes;
                    $versionId = DB::table('schedule_history_versions')->insertGetId([
                        'term_id' => $history->term_id,
                        'department_id' => $history->department_id,
                        'actor_user_id' => $history->actor_user_id,
                        'action' => $history->action,
                        'source' => 'legacy_backfill',
                        'reason' => null,
                        'change_summary' => $changes === null ? null : json_encode($changes, JSON_THROW_ON_ERROR),
                        'created_at' => $history->created_at,
                        'updated_at' => $history->updated_at,
                    ]);

                    DB::table('schedule_history_items')->insert([
                        'history_version_id' => $versionId,
                        'original_schedule_id' => $history->schedule_id,
                        'section_id' => $history->section_id,
                        'course_id' => $history->course_id,
                        'faculty_id' => data_get($snapshot, 'faculty_id'),
                        'room_id' => data_get($snapshot, 'room_id'),
                        'before_snapshot' => null,
                        'after_snapshot' => $snapshot === null ? null : json_encode($snapshot, JSON_THROW_ON_ERROR),
                        'snapshot_metadata' => json_encode(['legacy_history_id' => $history->id], JSON_THROW_ON_ERROR),
                        'created_at' => $history->created_at,
                        'updated_at' => $history->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('schedule_history_versions')->where('source', 'legacy_backfill')->delete();
    }
};
