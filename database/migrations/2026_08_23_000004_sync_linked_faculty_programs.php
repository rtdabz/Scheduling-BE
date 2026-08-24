<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('faculties')
            ->whereNotNull('user_id')
            ->whereIn('user_id', DB::table('users')->whereNotNull('program_id')->select('id'))
            ->update([
                'program_id' => DB::raw('(SELECT users.program_id FROM users WHERE users.id = faculties.user_id)'),
            ]);
    }

    public function down(): void
    {
        // Existing faculty program assignments must not be discarded on rollback.
    }
};
