<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            if (! Schema::hasColumn('rooms', 'allow_lecture_usage')) {
                $table->boolean('allow_lecture_usage')
                    ->default(false)
                    ->after('room_type');
            }
        });

        Schema::table('departments', function (Blueprint $table): void {
            if (Schema::hasColumn('departments', 'major_lecture_lab_room_fallback_enabled')) {
                $table->dropColumn('major_lecture_lab_room_fallback_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            if (! Schema::hasColumn('departments', 'major_lecture_lab_room_fallback_enabled')) {
                $table->boolean('major_lecture_lab_room_fallback_enabled')
                    ->default(false)
                    ->after('lecture_lab_lecture_online_default_enabled');
            }
        });

        Schema::table('rooms', function (Blueprint $table): void {
            if (Schema::hasColumn('rooms', 'allow_lecture_usage')) {
                $table->dropColumn('allow_lecture_usage');
            }
        });
    }
};
