<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->foreignId('room_id')->nullable()->change();
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->foreignId('room_id')->nullable(false)->change();
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
        });
    }
};
