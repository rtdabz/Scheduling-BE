<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a meeting whose instructor was assigned over a conflict on purpose:
 * double-booked at that time, or outside a part-timer's availability.
 *
 * The flag stands only while the meeting keeps the instructor, day and time it
 * was approved with; see FacultyConflictOverride.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->boolean('faculty_conflict_override')->default(false)->after('faculty_assignment_done');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('faculty_conflict_override');
        });
    }
};
