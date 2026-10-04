<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Field Course is no longer a saved department setting: a generation run
 * passes its own, and one class meets in the field by its delivery mode.
 * The saved flags are cleared, and a rule row left holding no other rule
 * (Required Day, Consecutive Days) is removed. Not reversible: nothing reads
 * the flag any more.
 */
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
        // The cleared flags are not restored.
    }
};
