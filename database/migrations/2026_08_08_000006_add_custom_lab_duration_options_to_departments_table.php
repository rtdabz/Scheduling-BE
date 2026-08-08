<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->boolean('custom_lab_duration_6_hours_enabled')
                ->default(false)
                ->after('custom_lab_duration_minutes');
            $table->boolean('custom_lab_duration_5_hours_enabled')
                ->default(false)
                ->after('custom_lab_duration_6_hours_enabled');
            $table->boolean('custom_lab_duration_other_enabled')
                ->default(false)
                ->after('custom_lab_duration_5_hours_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn([
                'custom_lab_duration_6_hours_enabled',
                'custom_lab_duration_5_hours_enabled',
                'custom_lab_duration_other_enabled',
            ]);
        });
    }
};
