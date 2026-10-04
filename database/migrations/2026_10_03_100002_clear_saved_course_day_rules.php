<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('department_course_rules')->delete();
    }

    public function down(): void
    {
    }
};
