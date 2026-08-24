<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->boolean('faculty_assignment_done')->default(false)->after('faculty_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', fn (Blueprint $table) => $table->dropColumn('faculty_assignment_done'));
    }
};
