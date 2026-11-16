<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A class handed back by its receiving department (delegation_returned_from_department_id)
     * is detached from the course-level delegation. The offering department may then hand that
     * one section to another department or program; null means it teaches the section itself.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->foreignId('section_teaching_department_id')->nullable()->after('delegation_returned_at')
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('section_teaching_program_id')->nullable()->after('section_teaching_department_id')
                ->constrained('programs')->nullOnDelete();
            $table->timestamp('section_teaching_assigned_at')->nullable()->after('section_teaching_program_id');
            $table->index(['semester_id', 'section_teaching_department_id'], 'schedules_section_teaching_index');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropIndex('schedules_section_teaching_index');
            $table->dropConstrainedForeignId('section_teaching_program_id');
            $table->dropConstrainedForeignId('section_teaching_department_id');
            $table->dropColumn('section_teaching_assigned_at');
        });
    }
};
