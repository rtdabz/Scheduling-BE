<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->index('is_active', 'terms_is_active_index');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->index(['department_id', 'status'], 'courses_department_status_index');
            $table->index(['course_category', 'status'], 'courses_category_status_index');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->index(['department_id', 'term_id', 'status'], 'sections_department_term_status_index');
        });

        Schema::table('faculties', function (Blueprint $table) {
            $table->index(['department_id', 'status'], 'faculties_department_status_index');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['term_id', 'department_id', 'status'], 'schedules_term_department_status_index');
            $table->index(['term_id', 'room_id', 'day', 'start_time', 'end_time'], 'schedules_room_conflict_index');
            $table->index(['term_id', 'faculty_id', 'day', 'start_time', 'end_time'], 'schedules_faculty_conflict_index');
            $table->index(['term_id', 'section_id', 'day', 'start_time', 'end_time'], 'schedules_section_conflict_index');
            $table->index(['term_id', 'section_id', 'course_id'], 'schedules_section_course_index');
        });

        Schema::table('schedule_recommendations', function (Blueprint $table) {
            $table->index(['department_id', 'status', 'created_at'], 'recommendations_department_status_created_index');
            $table->index(['section_id', 'status'], 'recommendations_section_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_recommendations', function (Blueprint $table) {
            $table->dropIndex('recommendations_department_status_created_index');
            $table->dropIndex('recommendations_section_status_index');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('schedules_term_department_status_index');
            $table->dropIndex('schedules_room_conflict_index');
            $table->dropIndex('schedules_faculty_conflict_index');
            $table->dropIndex('schedules_section_conflict_index');
            $table->dropIndex('schedules_section_course_index');
        });

        Schema::table('faculties', function (Blueprint $table) {
            $table->dropIndex('faculties_department_status_index');
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropIndex('sections_department_term_status_index');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('courses_department_status_index');
            $table->dropIndex('courses_category_status_index');
        });

        Schema::table('terms', function (Blueprint $table) {
            $table->dropIndex('terms_is_active_index');
        });
    }
};
