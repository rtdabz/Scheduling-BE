<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "never published" from "taken out of service".
 *
 * Deactivating a curriculum used to send it back to `draft`, which reads as
 * though it were still being written. A curriculum that taught real cohorts and
 * was then retired is not a draft, and the distinction matters now that a
 * department runs several curricula at once: the list has to say which ones were
 * withdrawn rather than never finished.
 *
 * Existing rows are left alone — nothing records whether a given draft was ever
 * active, so re-labelling them would be a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite has no ALTER ... MODIFY and does not enforce the enum anyway, so
        // the test database accepts the new value without help. Same approach as
        // the schedule-status migrations.
        if (! Schema::hasTable('curriculum') || DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE `curriculum` MODIFY `status` ENUM('draft', 'active', 'deactivated', 'archived') NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('curriculum')) {
            return;
        }

        // The narrowed enum cannot hold 'deactivated', so those rows fall back to
        // the status they used to carry.
        DB::table('curriculum')->where('status', 'deactivated')->update(['status' => 'draft']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE `curriculum` MODIFY `status` ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft'"
        );
    }
};
