<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who entered each schedule row, printed as the "Prepared by" signatory on the official schedule.
 * Existing rows are backfilled from schedule history: the earliest recorded actor on the row who is not
 * an approver (dean, VPAA, admin), since approvals and archives are recorded on the same rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });

        $creators = DB::table('schedule_history_items as items')
            ->join('schedule_history_versions as versions', 'versions.id', '=', 'items.history_version_id')
            ->join('users', 'users.id', '=', 'versions.actor_user_id')
            ->whereNotNull('items.original_schedule_id')
            ->whereNotIn('users.role', ['dean', 'vpaa', 'admin'])
            ->orderBy('versions.id')
            ->get(['items.original_schedule_id', 'versions.actor_user_id']);

        $firstCreator = [];
        foreach ($creators as $row) {
            $firstCreator[(int) $row->original_schedule_id] ??= (int) $row->actor_user_id;
        }

        foreach (collect($firstCreator)->groupBy(fn (int $userId) => $userId, true) as $userId => $scheduleIds) {
            foreach (array_chunk(array_keys($scheduleIds->all()), 500) as $chunk) {
                DB::table('schedules')->whereIn('id', $chunk)->whereNull('created_by')->update(['created_by' => $userId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
