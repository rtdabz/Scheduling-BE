<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The latest time a field class may end, set by the VPAA beside the operating
 * hours. It was hardcoded to 17:00 (SchedulingPolicy::FIELD_DAY_END_TIME);
 * 17:00 stays the default so existing schedules keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_settings', function (Blueprint $table): void {
            $table->time('field_end_time')->default('17:00:00')->after('closing_time');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_settings', function (Blueprint $table): void {
            $table->dropColumn('field_end_time');
        });
    }
};
