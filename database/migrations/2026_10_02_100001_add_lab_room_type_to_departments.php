<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('lab_room_type', 20)->default('laboratory');
        });

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
