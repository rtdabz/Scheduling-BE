<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops two department scheduling flags that nothing read.
 *
 * Both had a full round trip — migration, $fillable, $casts, validation rule,
 * assignment branch, payload key — and `split_units_schedule_override_enabled`
 * even had a toggle on the secretary Settings page. No scheduling code consumed
 * either one: the split behaviour they described is driven by the per-run options
 * the Generate wizard sends (`split_session_enabled`,
 * `selected_split_session_course_ids`), and `force_schedule_reuse_enabled` had no
 * feature behind it at all.
 *
 * `gec_split_schedule_override_enabled` is deliberately kept: it is read by the
 * Generate wizard to gate the GEC-split UI.
 *
 * See audit finding #36 in schedule_builder_audit_report.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            foreach (['split_units_schedule_override_enabled', 'force_schedule_reuse_enabled'] as $column) {
                if (Schema::hasColumn('departments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (! Schema::hasColumn('departments', 'split_units_schedule_override_enabled')) {
                $table->boolean('split_units_schedule_override_enabled')->default(false);
            }
            if (! Schema::hasColumn('departments', 'force_schedule_reuse_enabled')) {
                $table->boolean('force_schedule_reuse_enabled')->default(false);
            }
        });
    }
};
