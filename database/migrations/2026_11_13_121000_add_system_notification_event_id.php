<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_notifications', function (Blueprint $table): void {
            $table->uuid('event_id')->nullable();
            $table->unique(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'event_id']);
            $table->dropColumn('event_id');
        });
    }
};
