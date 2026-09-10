<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the curriculum `draft` status into `deactivated`.
 *
 * The two never described different things in practice. A curriculum is either
 * in service or it is not, and "draft" only ever meant "not in service yet",
 * which is the same state a withdrawn curriculum is in. Keeping both forced a
 * meaningless choice at creation time and gave the list two greyed-out pills
 * that behaved identically.
 *
 * Existing drafts become deactivated, which loses nothing: neither status was
 * selectable by a section, and both offered the same next step — activate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('curriculum')) {
            return;
        }

        // Rewrite the rows before narrowing the column, or MySQL truncates the
        // values it can no longer represent.
        DB::table('curriculum')->where('status', 'draft')->update(['status' => 'deactivated']);

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE `curriculum` MODIFY `status` ENUM('active', 'deactivated', 'archived') NOT NULL DEFAULT 'deactivated'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('curriculum') || DB::getDriverName() === 'sqlite') {
            return;
        }

        // Deactivated rows stay deactivated on the way back; which of them were
        // once drafts is not recorded, and guessing would be worse than keeping
        // the more accurate label.
        DB::statement(
            "ALTER TABLE `curriculum` MODIFY `status` ENUM('draft', 'active', 'deactivated', 'archived') NOT NULL DEFAULT 'deactivated'"
        );
    }
};
