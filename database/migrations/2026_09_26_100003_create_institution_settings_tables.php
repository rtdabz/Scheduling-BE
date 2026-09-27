<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('president_name', 150)->default('College President');
            $table->string('president_title', 150)->default('President');
            $table->time('opening_time')->default('07:00:00');
            $table->time('closing_time')->default('20:30:00');
            $table->time('field_end_time')->default('17:00:00');
            $table->integer('slot_interval')->default(30);
            $table->timestamps();
        });

        // InstitutionSetting::current() reads this single row.
        DB::table('institution_settings')->insert([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('timeslot_override', function (Blueprint $table): void {
            $table->id();
            $table->integer('duration_minutes');
            $table->time('start_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeslot_override');
        Schema::dropIfExists('institution_settings');
    }
};
