<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('custom_lab_duration_minutes')
                ->nullable()
                ->after('custom_lab_duration_override_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('custom_lab_duration_minutes');
        });
    }
};
