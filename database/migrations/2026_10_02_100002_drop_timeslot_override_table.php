<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('timeslot_override');
    }

    public function down(): void
    {
        Schema::create('timeslot_override', function (Blueprint $table): void {
            $table->id();
            $table->integer('duration_minutes');
            $table->time('start_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
