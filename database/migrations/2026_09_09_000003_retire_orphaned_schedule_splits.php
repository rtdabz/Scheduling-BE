<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires split rows whose schedule has been soft-deleted, and stops the
 * one-split-per-schedule rule from being only a validation rule.
 *
 * A split row carries the meeting-type metadata for exactly one schedule and
 * means nothing without it. The database cascade only fires on a hard delete,
 * and the bulk paths that soft-delete schedules never touched the splits, so
 * they were left live behind deleted owners. They are invisible through
 * Schedule::split(), but ScheduleSplitController::index() lists every live
 * split, so the strays surfaced there with a null schedule attached.
 *
 * The unique key is generated rather than plain: a retired split and its
 * replacement legitimately share a schedule_id, and MySQL treats null in a
 * unique index as distinct, so nulling the key on delete exempts exactly the
 * rows that should be exempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The retirement below is portable, but the generated column and its
        // unique key are MySQL/MariaDB-only. The test suite runs on in-memory
        // SQLite, which starts empty and so has nothing to retire either.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('schedule_splits')
            ->whereNull('schedule_splits.deleted_at')
            ->whereIn('schedule_splits.schedule_id', DB::table('schedules')->whereNotNull('deleted_at')->select('id'))
            ->update(['schedule_splits.deleted_at' => now()]);

        // A split whose schedule was hard-deleted at some point has nothing to
        // hang from at all; the foreign key would have taken it, but rows can
        // predate the constraint.
        DB::table('schedule_splits')
            ->whereNull('deleted_at')
            ->whereNotIn('schedule_id', DB::table('schedules')->select('id'))
            ->update(['deleted_at' => now()]);

        $duplicates = DB::table('schedule_splits')
            ->whereNull('deleted_at')
            ->select('schedule_id')
            ->groupBy('schedule_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'schedule_splits holds more than one live row for a schedule. '
                .'Resolve those before applying this migration.'
            );
        }

        // VIRTUAL, not STORED: a stored generated column forces a full table
        // rebuild, which MySQL refuses here because the table carries a foreign
        // key to schedules (error 1215). A virtual column is added in place and
        // still supports the unique index below.
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
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `schedule_splits` DROP INDEX `schedule_splits_live_schedule_unique`');
        DB::statement('ALTER TABLE `schedule_splits` DROP COLUMN `live_schedule_id`');

        // The retired rows are left retired: which of them had been deliberately
        // archived before this ran is no longer distinguishable.
    }
};
