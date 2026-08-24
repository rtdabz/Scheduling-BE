<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teaching-department override, as a column on the course itself.
 *
 * A course is owned by one department but may be *taught* by another: IT owns
 * GEC 101, the College of Arts and Sciences teaches it. `department_id` stays the
 * owner; this names the college whose instructors may be assigned instead.
 *
 * Null is the common case and means "no override" — `SchedulingPolicy::assignedTeachingDepartmentId`
 * then falls back to its derived rule, where a GEC subject is taught by the college
 * that offers it and any other minor is open to every department. A major is never
 * delegated, so this stays null for majors.
 *
 * This replaces the retired `course_teaching_assignments` table (dropped in
 * 2026_08_21_000001). A column rather than a row per course because every path that
 * reads the rule already has the Course model loaded, so the override costs no join.
 *
 * Indexed because the instructor-assignment and initial-data queries filter
 * schedules and courses on it to decide what the teaching department may see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('teaching_department_id')
                ->nullable()
                ->after('department_id')
                ->constrained('departments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropForeign(['teaching_department_id']);
            $table->dropColumn('teaching_department_id');
        });
    }
};
