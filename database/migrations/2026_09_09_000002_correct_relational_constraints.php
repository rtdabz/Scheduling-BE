<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three constraint corrections that the schema had drifted away from.
 *
 * 1. `schedules.room_id` cascaded on delete even after the column was made
 *    nullable to support online and Room TBA meetings. Deleting a room would
 *    have hard-deleted every schedule held in it, straight past the soft-delete
 *    that protects the rest of the table. Losing the room is not losing the
 *    class -- the meeting falls back to Room TBA, which the generator already
 *    understands -- so the reference is cleared instead.
 *
 * 2. `users.department_id` carried no foreign key at all, while every sibling
 *    scoping column has one. Nothing stopped a user pointing at a department
 *    that no longer exists.
 *
 * 3. `terms` had no uniqueness on its natural key, though the seeder and the
 *    controller both treat (academic_year, semester) as one. The constraint has
 *    to exempt soft-deleted rows, because deleting a term and recreating it is
 *    a supported flow; a generated column that goes null on delete gives
 *    exactly that, since MySQL treats null as distinct in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every step here is MySQL/MariaDB-specific: SQLite cannot drop a
        // foreign key in place, and it has no generated columns to hang the
        // live-only unique key on. The test suite runs on in-memory SQLite,
        // where these refinements are neither available nor needed.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropForeign(['room_id']);
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });

        // A user pointing at a department that was already deleted would block
        // the constraint from being created at all.
        DB::table('users')
            ->whereNotNull('department_id')
            ->whereNotIn('department_id', DB::table('departments')->select('id'))
            ->update(['department_id' => null]);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        $duplicateTerms = DB::table('terms')
            ->whereNull('deleted_at')
            ->select('academic_year', 'semester')
            ->groupBy('academic_year', 'semester')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateTerms) {
            throw new RuntimeException(
                'terms holds duplicate live (academic_year, semester) pairs. '
                .'Merge them before applying this migration.'
            );
        }

        // VIRTUAL rather than STORED: it avoids a full table rebuild, and a
        // virtual column still carries a unique index.
        DB::statement(
            'ALTER TABLE `terms` ADD COLUMN `term_key` VARCHAR(300) '
            ."GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, CONCAT(`academic_year`, '|', `semester`), NULL)) VIRTUAL"
        );
        DB::statement('ALTER TABLE `terms` ADD UNIQUE KEY `terms_live_term_key_unique` (`term_key`)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `terms` DROP INDEX `terms_live_term_key_unique`');
        DB::statement('ALTER TABLE `terms` DROP COLUMN `term_key`');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
        });

        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropForeign(['room_id']);
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
        });
    }
};
