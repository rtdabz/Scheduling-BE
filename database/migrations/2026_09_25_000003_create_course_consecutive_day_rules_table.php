<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutive Days: a course a department schedules as one class met on N
 * calendar-consecutive days at the same time (e.g. a clinical duty on
 * Thursday, Friday and Saturday).
 *
 * `section_id` null is the course-wide rule for every section of the course in
 * the department; a section's own row overrides it. The unique index cannot
 * stop two course-wide rows (NULLs never collide), so the settings sync
 * replaces a course's rules as a whole instead of inserting beside them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_consecutive_day_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_count');
            $table->enum('preferred_start_day', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'course_id', 'section_id'], 'course_consecutive_day_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_consecutive_day_rules');
    }
};
