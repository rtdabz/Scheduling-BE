<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('department_forced_schedule_course_codes');
    }

    public function down(): void
    {
        Schema::create('department_forced_schedule_course_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('course_code');
            $table->timestamps();

            $table->unique(['department_id', 'course_code'], 'department_forced_schedule_course_code_unique');
        });
    }
};
