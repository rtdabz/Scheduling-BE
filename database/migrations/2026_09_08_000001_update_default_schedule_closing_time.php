<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule_settings')) {
            return;
        }

        DB::table('schedule_settings')
            ->where('closing_time', '19:00:00')
            ->update(['closing_time' => '20:30:00']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule_settings')) {
            return;
        }

        DB::table('schedule_settings')
            ->where('closing_time', '20:30:00')
            ->update(['closing_time' => '19:00:00']);
    }
};
