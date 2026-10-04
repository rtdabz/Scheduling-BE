<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->foreignId('snapshot_version_id')
                ->nullable()
                ->after('subject_count')
                ->constrained('schedule_history_versions')
                ->nullOnDelete();
        });

        DB::table('scheduling_audit_logs')
            ->where('action', 'schedule_submitted')
            ->whereNotNull('schedule_submission_id')
            ->whereNotNull('history_version_id')
            ->orderBy('id')
            ->get(['schedule_submission_id', 'history_version_id'])
            ->each(function (object $log): void {
                DB::table('schedule_submissions')
                    ->where('id', $log->schedule_submission_id)
                    ->whereNull('snapshot_version_id')
                    ->update(['snapshot_version_id' => $log->history_version_id]);
            });
    }

    public function down(): void
    {
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('snapshot_version_id');
        });
    }
};
