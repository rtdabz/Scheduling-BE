<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three rules the application already enforces in code, now enforced by the
 * database as well. Existing data satisfies all three; the checks below refuse
 * to run rather than silently pick a winner if that ever stops being true.
 *
 * 1. A section name is unique within its department and semester, as
 *    Sections::nameTaken() checks. Names are normalized on save, so a plain
 *    composite key is enough.
 *
 * 2. Only one live semester is active at a time. Semester::saving() and the
 *    activation flow both deactivate the previous semester first.
 *
 * 3. Shared courses (no department) have unique codes. The existing
 *    (course_code, department_id) key cannot catch these, since a unique index
 *    treats every null department as distinct. Like that key, this one also
 *    covers soft-deleted rows.
 *
 * Rules 2 and 3 hang on generated columns that go null when the rule does not
 * apply, the same technique as semesters.semester_key. They are MySQL/MariaDB
 * only; the in-memory SQLite test database skips them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateSections = DB::table('sections')
            ->select('department_id', 'semester_id', 'section_name')
            ->groupBy('department_id', 'semester_id', 'section_name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateSections) {
            throw new RuntimeException('sections holds duplicate names within a department and semester. Rename them before applying this migration.');
        }

        Schema::table('sections', function (Blueprint $table): void {
            $table->unique(['department_id', 'semester_id', 'section_name'], 'sections_department_semester_name_unique');
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('semesters')->where('is_active', true)->whereNull('deleted_at')->count() > 1) {
            throw new RuntimeException('More than one semester is active. Deactivate all but one before applying this migration.');
        }

        $duplicateSharedCodes = DB::table('courses')
            ->whereNull('department_id')
            ->select('course_code')
            ->groupBy('course_code')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateSharedCodes) {
            throw new RuntimeException('courses holds shared courses with duplicate codes. Merge them before applying this migration.');
        }

        DB::statement(
            'ALTER TABLE `semesters` ADD COLUMN `active_key` TINYINT '
            .'GENERATED ALWAYS AS (IF(`is_active` = 1 AND `deleted_at` IS NULL, 1, NULL)) VIRTUAL'
        );
        DB::statement('ALTER TABLE `semesters` ADD UNIQUE KEY `semesters_single_active_unique` (`active_key`)');

        DB::statement(
            'ALTER TABLE `courses` ADD COLUMN `shared_course_code` VARCHAR(255) '
            .'GENERATED ALWAYS AS (IF(`department_id` IS NULL, `course_code`, NULL)) VIRTUAL'
        );
        DB::statement('ALTER TABLE `courses` ADD UNIQUE KEY `courses_shared_code_unique` (`shared_course_code`)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `courses` DROP INDEX `courses_shared_code_unique`');
            DB::statement('ALTER TABLE `courses` DROP COLUMN `shared_course_code`');
            DB::statement('ALTER TABLE `semesters` DROP INDEX `semesters_single_active_unique`');
            DB::statement('ALTER TABLE `semesters` DROP COLUMN `active_key`');
        }

        Schema::table('sections', function (Blueprint $table): void {
            $table->dropUnique('sections_department_semester_name_unique');
        });
    }
};
