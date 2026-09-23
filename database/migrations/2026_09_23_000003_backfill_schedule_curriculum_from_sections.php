<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schedules saved through store() and batch() never recorded curriculum_id,
 * so every such row is null. Stamp each one with its section's curriculum.
 * Rows that already carry a curriculum are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('schedules', 'curriculum_id') || ! Schema::hasColumn('sections', 'curriculum_id')) {
            return;
        }

        DB::table('schedules')
            ->whereNull('curriculum_id')
            ->update([
                'curriculum_id' => DB::raw('(SELECT sections.curriculum_id FROM sections WHERE sections.id = schedules.section_id)'),
            ]);
    }

    public function down(): void
    {
        // The pre-backfill nulls carried no information; nothing to restore.
    }
};
