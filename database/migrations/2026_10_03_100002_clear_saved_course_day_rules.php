<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Required Day and Consecutive Days are no longer saved department settings:
 * a generation run passes its own, and nothing reads the saved rows. The
 * rows left from earlier runs (e.g. a department's NSTP 1 on Saturday) are
 * removed. Not reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('department_course_rules')->delete();
    }

    public function down(): void
    {
        // The removed rules are not restored.
    }
};
