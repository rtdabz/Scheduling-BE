<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rooms')
            ->where('room_type', 'online')
            ->update(['max_concurrent_classes' => 3]);
    }

    public function down(): void
    {
        DB::table('rooms')
            ->where('room_type', 'online')
            ->update(['max_concurrent_classes' => 1]);
    }
};
