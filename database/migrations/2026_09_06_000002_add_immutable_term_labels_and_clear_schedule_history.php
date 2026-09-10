<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_history_versions', function (Blueprint $table): void {
            $table->string('academic_year', 50)->nullable()->after('term_id');
            $table->string('semester', 30)->nullable()->after('academic_year');
        });

        // Existing records were created under the previous history behavior and
        // are intentionally removed so the new table contains only complete,
        // VPAA-approved whole-term archives created after this migration.
        DB::table('scheduling_audit_logs')->whereNotNull('history_version_id')->update(['history_version_id' => null]);
        DB::table('schedule_history_items')->delete();
        DB::table('schedule_history_versions')->delete();
    }

    public function down(): void
    {
        Schema::table('schedule_history_versions', function (Blueprint $table): void {
            $table->dropColumn(['academic_year', 'semester']);
        });
    }
};
