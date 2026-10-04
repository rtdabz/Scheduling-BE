<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table): void {
            $table->id();
            $table->string('section_name');
            $table->enum('year_level', ['1', '2', '3', '4']);
            $table->enum('semester', ['1st', '2nd', 'summer']);
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('curriculum_id')->nullable()->constrained('curriculum');
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->unique(['department_id', 'semester_id', 'section_name'], 'sections_department_semester_name_unique');
            $table->index(['department_id', 'semester_id', 'status'], 'sections_department_term_status_index');
            $table->index(['department_id', 'program_id', 'semester_id'], 'sections_department_program_term_index');
            $table->index(['semester_id', 'department_id', 'year_level', 'curriculum_id'], 'sections_curriculum_lookup_index');
        });

        Schema::create('department_course_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->cascadeOnDelete();
            $table->enum('forced_day', self::DAYS)->nullable();
            $table->boolean('is_field')->default(false);
            $table->unsignedTinyInteger('consecutive_day_count')->nullable();
            $table->enum('preferred_start_day', self::DAYS)->nullable();
            $table->string('meeting_days', 80)->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'course_id', 'section_id'], 'department_course_rule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_course_rules');
        Schema::dropIfExists('sections');
    }
};
