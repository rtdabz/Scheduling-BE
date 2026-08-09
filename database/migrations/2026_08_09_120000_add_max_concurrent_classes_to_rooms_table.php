<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_concurrent_classes')->default(1)->after('status');
        });

        DB::table('rooms')
            ->where('room_type', 'field')
            ->update(['max_concurrent_classes' => 3]);
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('max_concurrent_classes');
        });
    }
};
