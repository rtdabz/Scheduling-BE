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

    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            if (Schema::hasColumn('rooms', 'allow_lecture_usage')) {
                $table->dropColumn('allow_lecture_usage');
            }
        });
    }
};
