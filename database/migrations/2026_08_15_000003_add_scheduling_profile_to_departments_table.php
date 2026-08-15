<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('scheduling_profile', 32)->default('standard')->after('department_code');
        });

        DB::table('departments')
            ->whereIn('department_code', ['IT', 'CIT', 'HM', 'CHM', 'MID', 'CM', 'MIDWIFERY'])
            ->update(['scheduling_profile' => 'laboratory_enabled']);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('scheduling_profile');
        });
    }
};
