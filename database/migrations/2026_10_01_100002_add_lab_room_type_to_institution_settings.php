<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution_settings', function (Blueprint $table): void {
            $table->string('lab_room_type', 20)->default('laboratory')->after('field_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('institution_settings', function (Blueprint $table): void {
            $table->dropColumn('lab_room_type');
        });
    }
};
