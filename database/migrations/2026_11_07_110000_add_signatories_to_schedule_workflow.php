<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the names and positions printed in a schedule's signature block (Prepared by, Reviewed by,
 * Recommended by, Approved by) with each submission and each Approved / Rejected / Recalled history entry,
 * so replacing an official or editing their account no longer rewrites past schedules.
 *
 * Legacy names remain unknown. Present-day officeholders cannot establish past identities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->json('signatories')->nullable()->after('approval_override_reason');
        });
        Schema::table('schedule_history_versions', function (Blueprint $table): void {
            $table->json('signatories')->nullable()->after('change_summary');
        });

    }

    public function down(): void
    {
        Schema::table('schedule_history_versions', function (Blueprint $table): void {
            $table->dropColumn('signatories');
        });
        Schema::table('schedule_submissions', function (Blueprint $table): void {
            $table->dropColumn('signatories');
        });
    }
};
