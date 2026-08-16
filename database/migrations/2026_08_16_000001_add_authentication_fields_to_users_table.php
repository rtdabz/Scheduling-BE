<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('username');
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('allow_google_login')->default(false)->after('is_active');
            $table->string('google_id')->nullable()->unique()->after('allow_google_login');
            $table->string('google_email')->nullable()->after('google_id');
            $table->timestamp('google_linked_at')->nullable()->after('google_email');
            $table->timestamp('last_login_at')->nullable()->after('google_linked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->dropUnique('users_google_id_unique');
            $table->dropColumn([
                'email',
                'is_active',
                'allow_google_login',
                'google_id',
                'google_email',
                'google_linked_at',
                'last_login_at',
            ]);
        });
    }
};
