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
        Schema::create('schedule_settings', function (Blueprint $table) {
            $table->id();

            $table->time('opening_time')
                ->default('07:00:00');

            $table->time('closing_time')
                ->default('19:00:00');

            $table->integer('slot_interval')
                ->default(30);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_settings');
    }
};
