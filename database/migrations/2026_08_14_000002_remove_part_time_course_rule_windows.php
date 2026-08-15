<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('department_part_time_course_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('department_part_time_course_rules', 'allowed_days')) {
                $table->dropColumn('allowed_days');
            }
            if (Schema::hasColumn('department_part_time_course_rules', 'preferred_start_time')) {
                $table->dropColumn('preferred_start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('department_part_time_course_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('department_part_time_course_rules', 'allowed_days')) {
                $table->json('allowed_days')->nullable();
            }
            if (! Schema::hasColumn('department_part_time_course_rules', 'preferred_start_time')) {
                $table->time('preferred_start_time')->default('17:00:00');
            }
        });
    }
};
