<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('department_course_rules')->where('is_field', true)->update(['is_field' => false]);

        DB::table('department_course_rules')
            ->where('is_field', false)
            ->whereNull('forced_day')
            ->whereNull('consecutive_day_count')
            ->whereNull('preferred_start_day')
            ->whereNull('meeting_days')
            ->delete();
    }

    public function down(): void
    {
    }
};
