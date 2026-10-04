<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const STATUSES = [
        'draft',
        'completed',
        'submitted',
        'approved_by_dean',
        'conditionally_approved',
        'rejected_by_dean',
        'approved',
        'faculty_assignment',
        'reassignment',
        'finalized',
        'rejected',
        'revision',
    ];

    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('curriculum_id')->nullable()->constrained('curriculum')->nullOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->boolean('faculty_assignment_done')->default(false);
            $table->boolean('faculty_conflict_override')->default(false);
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->enum('day', self::DAYS);
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('mode', ['on-site', 'online', 'field'])->default('on-site');
            $table->boolean('is_hybrid')->default(false);
            $table->string('preferred_pattern', 20)->nullable();
            $table->enum('status', self::STATUSES)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['semester_id', 'department_id', 'status'], 'schedules_term_department_status_index');
            $table->index(['semester_id', 'room_id', 'day', 'start_time', 'end_time'], 'schedules_room_conflict_index');
            $table->index(['semester_id', 'faculty_id', 'day', 'start_time', 'end_time'], 'schedules_faculty_conflict_index');
            $table->index(['semester_id', 'section_id', 'day', 'start_time', 'end_time'], 'schedules_section_conflict_index');
            $table->index(['semester_id', 'section_id', 'course_id'], 'schedules_section_course_index');
            $table->index(['department_id', 'program_id', 'semester_id'], 'schedules_department_program_term_index');
        });

        Schema::create('schedule_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->uuid('split_group_id')->nullable()->index();
            $table->enum('meeting_type', ['lecture', 'laboratory'])->nullable();
            $table->unsignedTinyInteger('meeting_index')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `schedule_splits` ADD COLUMN `live_schedule_id` BIGINT UNSIGNED '
            .'GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `schedule_id`, NULL)) VIRTUAL'
        );
        DB::statement(
            'ALTER TABLE `schedule_splits` ADD UNIQUE KEY `schedule_splits_live_schedule_unique` (`live_schedule_id`)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_splits');
        Schema::dropIfExists('schedules');
    }
};
