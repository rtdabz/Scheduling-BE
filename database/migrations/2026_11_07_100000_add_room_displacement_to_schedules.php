<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Face-to-face classes whose room is closed run online temporarily until a reviewed reassignment is approved.
 * displaced_room_id records the closed room so these rows stay distinct from classes that were planned online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->foreignId('displaced_room_id')->nullable()->after('room_id')->constrained('rooms')->nullOnDelete();
            $table->timestamp('displaced_at')->nullable()->after('displaced_room_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('displaced_room_id');
            $table->dropColumn('displaced_at');
        });
    }
};
