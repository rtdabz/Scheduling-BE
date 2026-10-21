<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('scheduling_profile');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            // The retired classification cannot be reconstructed from course data.
            $table->string('scheduling_profile', 32)->default('standard');
        });
    }
};
