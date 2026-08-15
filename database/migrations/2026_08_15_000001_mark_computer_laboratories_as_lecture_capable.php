<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rooms', 'allow_lecture_usage')) {
            return;
        }

        DB::table('rooms')
            ->where('room_type', 'laboratory')
            ->where('room_code', 'like', 'CompLab%')
            ->update(['allow_lecture_usage' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rooms', 'allow_lecture_usage')) {
            return;
        }

        DB::table('rooms')
            ->where('room_type', 'laboratory')
            ->where('room_code', 'like', 'CompLab%')
            ->update(['allow_lecture_usage' => false]);
    }
};
