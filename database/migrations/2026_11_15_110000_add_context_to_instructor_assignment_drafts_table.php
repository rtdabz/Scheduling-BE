<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separates the Assign Instructors list of Schedule Management ("schedule") from the
     * lists queued on Cross-Department Assignments ("cross_department:{offering dept id}"),
     * so restoring one never drops the classes queued in the other.
     */
    public function up(): void
    {
        Schema::table('instructor_assignment_drafts', function (Blueprint $table): void {
            $table->string('context', 40)->default('schedule')->after('semester_id');
        });

        // Add the new unique index before dropping the old one: MySQL keeps using an index
        // that starts with user_id for the user_id foreign key.
        Schema::table('instructor_assignment_drafts', function (Blueprint $table): void {
            $table->unique(['user_id', 'department_id', 'semester_id', 'context'], 'instructor_assignment_drafts_context_unique');
        });
        Schema::table('instructor_assignment_drafts', function (Blueprint $table): void {
            $table->dropUnique('instructor_assignment_drafts_scope_unique');
        });
    }

    public function down(): void
    {
        // Only the Schedule Management list fits the old one-draft-per-scope index.
        \Illuminate\Support\Facades\DB::table('instructor_assignment_drafts')->where('context', '!=', 'schedule')->delete();

        Schema::table('instructor_assignment_drafts', function (Blueprint $table): void {
            $table->unique(['user_id', 'department_id', 'semester_id'], 'instructor_assignment_drafts_scope_unique');
        });
        Schema::table('instructor_assignment_drafts', function (Blueprint $table): void {
            $table->dropUnique('instructor_assignment_drafts_context_unique');
            $table->dropColumn('context');
        });
    }
};
