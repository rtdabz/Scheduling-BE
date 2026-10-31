<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Class time now always follows the department's unit-to-hour conversion.
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn([
                'custom_lab_duration_override_enabled',
                'custom_lab_duration_minutes',
                'custom_lab_duration_6_hours_enabled',
                'custom_lab_duration_5_hours_enabled',
                'custom_lab_duration_other_enabled',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->boolean('custom_lab_duration_override_enabled')->default(false)->after('lecture_lab_schedule_override_enabled');
            $table->unsignedSmallInteger('custom_lab_duration_minutes')->nullable()->after('custom_lab_duration_override_enabled');
            $table->boolean('custom_lab_duration_6_hours_enabled')->default(false)->after('custom_lab_duration_minutes');
            $table->boolean('custom_lab_duration_5_hours_enabled')->default(false)->after('custom_lab_duration_6_hours_enabled');
            $table->boolean('custom_lab_duration_other_enabled')->default(false)->after('custom_lab_duration_5_hours_enabled');
        });
    }
};
