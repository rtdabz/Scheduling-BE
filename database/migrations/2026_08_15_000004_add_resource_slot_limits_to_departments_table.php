<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('online_slot_limit')->default(3)->after('sunday_online_only_enabled');
            $table->unsignedSmallInteger('field_slot_limit')->default(3)->after('online_slot_limit');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn(['online_slot_limit', 'field_slot_limit']);
        });
    }
};
