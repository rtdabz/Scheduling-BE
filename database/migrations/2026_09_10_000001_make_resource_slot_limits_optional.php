<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the online and field concurrency limits optional, defaulting to none.
 *
 * Neither is a room. An online class occupies no space, and the field is open
 * ground several sections can share. Capping them at three modelled a scarcity
 * that does not exist, and the cap became the binding constraint on entire year
 * levels: a three-hour NSTP course pinned to Saturday has three legal starts, so
 * a field limit of three allowed nine placements for a year level needing
 * twenty-one, and the run failed with no indication that a setting caused it.
 *
 * Existing values are cleared so the caps stop binding. A department that
 * genuinely needs a ceiling can set one again, and it is then enforced exactly
 * as before -- with the year-level pre-check reporting the figure required when
 * it binds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('online_slot_limit')->nullable()->default(null)->change();
            $table->unsignedSmallInteger('field_slot_limit')->nullable()->default(null)->change();
        });

        DB::table('departments')->update([
            'online_slot_limit' => null,
            'field_slot_limit' => null,
        ]);
    }

    public function down(): void
    {
        DB::table('departments')
            ->whereNull('online_slot_limit')
            ->update(['online_slot_limit' => 3]);

        DB::table('departments')
            ->whereNull('field_slot_limit')
            ->update(['field_slot_limit' => 3]);

        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('online_slot_limit')->default(3)->change();
            $table->unsignedSmallInteger('field_slot_limit')->default(3)->change();
        });
    }
};
