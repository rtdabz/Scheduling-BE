<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sunday is an ordinary teaching day, so a department no longer decides whether
 * its Sunday classes must be online. The rule this column gated
 * (major_sunday_mode_constraint) is gone, along with the field and minor day
 * limits, so every course may use every day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('departments', 'sunday_online_only_enabled')) {
            return;
        }

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('sunday_online_only_enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('departments', 'sunday_online_only_enabled')) {
            return;
        }

        Schema::table('departments', function (Blueprint $table): void {
            // Restored open, not at the old default: rolling back should not
            // silently re-restrict every department's Sunday.
            $table->boolean('sunday_online_only_enabled')->default(false);
        });
    }
};
