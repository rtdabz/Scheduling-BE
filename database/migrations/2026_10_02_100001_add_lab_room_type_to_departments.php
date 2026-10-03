<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Default LAB Room Requirement moves from the institution to each
 * department (Generate Schedule Step 2 → Default Settings): the room every
 * course's laboratory meetings may use -- 'laboratory' (the original rule),
 * 'lecture' (a regular classroom) or 'either'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('lab_room_type', 20)->default('laboratory');
        });

        // Every department starts from the institution's existing rule.
        $current = DB::table('institution_settings')->value('lab_room_type');
        if (in_array($current, ['lecture', 'either'], true)) {
            DB::table('departments')->update(['lab_room_type' => $current]);
        }

        Schema::table('institution_settings', function (Blueprint $table): void {
            $table->dropColumn('lab_room_type');
        });
    }

    public function down(): void
    {
        Schema::table('institution_settings', function (Blueprint $table): void {
            $table->string('lab_room_type', 20)->default('laboratory')->after('field_end_time');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('lab_room_type');
        });
    }
};
