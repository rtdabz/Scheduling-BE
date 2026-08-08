<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('curriculum_course')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('curricula')
                    ->join('courses', 'courses.id', '=', 'curriculum_course.course_id')
                    ->whereColumn('curricula.id', 'curriculum_course.curriculum_id')
                    ->whereNotNull('courses.department_id')
                    ->whereNotNull('curricula.department_id')
                    ->whereColumn('courses.department_id', '!=', 'curricula.department_id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Removed cross-department links cannot be restored safely.
    }
};
