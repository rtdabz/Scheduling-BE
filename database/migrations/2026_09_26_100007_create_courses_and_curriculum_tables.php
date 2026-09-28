<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('course_code');
            $table->string('course_name');
            $table->integer('lecture_hours')->default(0);
            $table->integer('lab_hours')->default(0);
            $table->integer('units')->default(0);
            $table->enum('course_category', ['major', 'minor']);
            $table->enum('room_type_required', ['lecture', 'laboratory', 'field', 'online'])->default('lecture');
            $table->enum('year_level', ['1', '2', '3', '4']);
            $table->enum('semester', ['1st', '2nd', 'summer']);
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('teaching_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('teaching_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_code', 'department_id']);
            $table->index(['department_id', 'status'], 'courses_department_status_index');
            $table->index(['course_category', 'status'], 'courses_category_status_index');
        });

        Schema::create('curriculum', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('effective_school_year');
            $table->enum('status', ['active', 'deactivated', 'archived'])->default('deactivated');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('curriculum_course', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curriculum')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->unsignedTinyInteger('semester');
            $table->timestamps();
            $table->unique(['curriculum_id', 'course_id']);
            $table->index(['curriculum_id', 'year_level', 'semester'], 'curriculum_course_term_lookup_index');
        });

        // Shared courses (no department) have unique codes. The composite key
        // above cannot catch these, since a unique index treats every null
        // department as distinct. MySQL/MariaDB only, like semesters.semester_key.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `courses` ADD COLUMN `shared_course_code` VARCHAR(255) '
            .'GENERATED ALWAYS AS (IF(`department_id` IS NULL, `course_code`, NULL)) VIRTUAL'
        );
        DB::statement('ALTER TABLE `courses` ADD UNIQUE KEY `courses_shared_code_unique` (`shared_course_code`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_course');
        Schema::dropIfExists('curriculum');
        Schema::dropIfExists('courses');
    }
};
