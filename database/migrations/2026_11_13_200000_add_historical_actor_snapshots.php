<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately no backfill: today's accounts cannot establish yesterday's identities.
        foreach (['schedule_submissions', 'room_requests', 'room_recovery_plans'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('actor_snapshots')->nullable());
        }
        Schema::table('schedules', fn (Blueprint $table) => $table->json('creator_identity')->nullable());
    }

    public function down(): void
    {
        throw new RuntimeException('Actor snapshots are permanent historical evidence; this migration cannot be rolled back.');
    }
};
