<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('allow_google_login', false)->update(['allow_google_login' => true]);
    }

    public function down(): void
    {
        // Existing account-level Google-login choices cannot be reconstructed.
    }
};
