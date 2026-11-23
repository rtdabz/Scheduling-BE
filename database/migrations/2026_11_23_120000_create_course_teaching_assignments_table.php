<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_teaching_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->foreignId('teaching_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('teaching_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->foreignId('teaching_source_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->boolean('is_legacy_snapshot')->default(false);
            $table->timestamps();
            $table->unique(['course_id', 'semester_id']);
            $table->index(['semester_id', 'teaching_department_id'], 'course_teaching_semester_department_index');
        });

        // Freeze the effective pre-upgrade interpretation for existing classes and the active
        // workspace. This preserves current behavior; it does not reconstruct historical decisions.
        // An inactive semester must have evidence of prior activation. Merely plotting future
        // draft classes must not cause the incoming semester to inherit a delegation.
        $columns = ['course_id', 'semester_id', 'teaching_department_id', 'teaching_program_id',
            'teaching_source_program_id', 'is_legacy_snapshot', 'created_at', 'updated_at'];
        $select = ['courses.id', 'semesters.id', 'courses.teaching_department_id', 'courses.teaching_program_id',
            'courses.teaching_source_program_id', DB::raw('1'), DB::raw('CURRENT_TIMESTAMP'), DB::raw('CURRENT_TIMESTAMP')];
        $active = DB::table('courses')->crossJoin('semesters')
            ->where('semesters.is_active', true)->whereNull('semesters.deleted_at')
            ->whereNotNull('courses.teaching_department_id')->select($select);
        $existing = DB::table('courses')->join('schedules', 'schedules.course_id', '=', 'courses.id')
            ->join('semesters', 'semesters.id', '=', 'schedules.semester_id')
            ->whereNull('schedules.delegation_returned_from_department_id')
            ->where(function ($used): void {
                $used->whereExists(fn ($audit) => $audit->selectRaw('1')->from('scheduling_audit_logs')
                    ->whereColumn('scheduling_audit_logs.semester_id', 'semesters.id')
                    ->whereIn('action', ['semester_activated', 'semester_ended', 'schedule_semester_archived']))
                    ->orWhereExists(fn ($history) => $history->selectRaw('1')->from('schedule_history_versions')
                        ->whereColumn('schedule_history_versions.semester_id', 'semesters.id')
                        ->where('action', 'schedule_semester_archived'));
            })
            ->whereNotNull('courses.teaching_department_id')->select($select)->distinct();
        DB::table('course_teaching_assignments')->insertUsing($columns, $active->union($existing));
    }

    public function down(): void
    {
        Schema::dropIfExists('course_teaching_assignments');
    }
};
