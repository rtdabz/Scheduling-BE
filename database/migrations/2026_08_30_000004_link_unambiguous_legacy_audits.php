<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduling_audit_logs')
            ->whereNull('history_version_id')
            ->orderBy('id')
            ->chunkById(200, function ($audits): void {
                foreach ($audits as $audit) {
                    $candidates = DB::table('schedule_history_versions')
                        ->where('source', 'legacy_backfill')
                        ->where('action', $audit->action)
                        ->when($audit->term_id === null, fn ($q) => $q->whereNull('term_id'), fn ($q) => $q->where('term_id', $audit->term_id))
                        ->when($audit->department_id === null, fn ($q) => $q->whereNull('department_id'), fn ($q) => $q->where('department_id', $audit->department_id))
                        ->whereBetween('created_at', [$audit->created_at, $audit->created_at])
                        ->pluck('id');

                    if ($candidates->count() === 1) {
                        DB::table('scheduling_audit_logs')->where('id', $audit->id)->update(['history_version_id' => $candidates->first()]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('scheduling_audit_logs')
            ->whereIn('history_version_id', DB::table('schedule_history_versions')->where('source', 'legacy_backfill')->select('id'))
            ->update(['history_version_id' => null]);
    }
};
