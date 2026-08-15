<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('department_part_time_course_rules');
    }

    public function down(): void
    {
        if (! Schema::hasTable('department_part_time_course_rules')) {
            Schema::create('department_part_time_course_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['department_id', 'course_id'], 'department_part_time_course_rule_unique');
            });
        }
    }
};
