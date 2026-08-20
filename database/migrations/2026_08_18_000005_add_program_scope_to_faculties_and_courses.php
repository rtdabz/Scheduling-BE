<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programs existed only to scope user accounts, so nothing recorded which major a
 * course belongs to or which major an instructor teaches. Restricting major
 * subjects to instructors of the corresponding major needs both sides on record.
 *
 * Both columns are nullable: a course with no program keeps the department-level
 * rule alone, so existing data schedules exactly as before until a program is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table): void {
            $table->foreignId('program_id')
                ->nullable()
                ->after('department_id')
                ->constrained('programs')
                ->nullOnDelete();
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('program_id')
                ->nullable()
                ->after('department_id')
                ->constrained('programs')
                ->nullOnDelete();
        });

        $this->backfillFacultyProgramsFromLinkedUsers();
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('program_id');
        });

        Schema::table('faculties', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('program_id');
        });
    }

    /**
     * Faculty profiles linked to a user account can inherit that account's program
     * so departments do not start from a blank slate. Only applied when the
     * account's department matches the profile's, since a mismatch means the two
     * records disagree about where the instructor belongs.
     */
    private function backfillFacultyProgramsFromLinkedUsers(): void
    {
        if (! Schema::hasColumn('faculties', 'user_id') || ! Schema::hasColumn('users', 'program_id')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('program_id')
            ->orderBy('id')
            ->select(['id', 'program_id', 'department_id'])
            ->chunk(200, function ($users): void {
                foreach ($users as $user) {
                    DB::table('faculties')
                        ->where('user_id', $user->id)
                        ->whereNull('program_id')
                        ->where('department_id', $user->department_id)
                        ->update(['program_id' => $user->program_id]);
                }
            });
    }
};
