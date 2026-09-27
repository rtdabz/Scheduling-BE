<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('department_name')->unique();
            $table->string('department_code')->unique();
            $table->string('scheduling_profile', 32)->default('standard');
            $table->longText('logo')->nullable();
            $table->boolean('lecture_lab_schedule_override_enabled')->default(false);
            $table->boolean('custom_lab_duration_override_enabled')->default(false);
            $table->unsignedSmallInteger('custom_lab_duration_minutes')->nullable();
            $table->boolean('custom_lab_duration_6_hours_enabled')->default(false);
            $table->boolean('custom_lab_duration_5_hours_enabled')->default(false);
            $table->boolean('custom_lab_duration_other_enabled')->default(false);
            $table->boolean('gec_split_schedule_override_enabled')->default(false);
            $table->boolean('major_lecture_split_schedule_override_enabled')->default(false);
            $table->boolean('sunday_classes_enabled')->default(false);
            $table->string('room_sharing_policy', 16)->default('open');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('major')->default('');
            $table->string('code');
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['department_id', 'code', 'major'], 'programs_department_code_major_unique');
            $table->index(['department_id', 'major']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
        Schema::dropIfExists('departments');
    }
};
