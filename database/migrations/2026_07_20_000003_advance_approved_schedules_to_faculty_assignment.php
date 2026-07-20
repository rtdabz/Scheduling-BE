<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('schedules')
            ->where('status', 'approved')
            ->update([
                'status' => 'faculty_assignment',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
    }
};
