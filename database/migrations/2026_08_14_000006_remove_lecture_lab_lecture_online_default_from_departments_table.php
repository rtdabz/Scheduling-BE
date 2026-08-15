<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (Schema::hasColumn('departments', 'lecture_lab_lecture_online_default_enabled')) {
                $table->dropColumn('lecture_lab_lecture_online_default_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (! Schema::hasColumn('departments', 'lecture_lab_lecture_online_default_enabled')) {
                $table->boolean('lecture_lab_lecture_online_default_enabled')
                    ->default(false)
                    ->after('lecture_lab_schedule_override_enabled');
            }
        });
    }
};
