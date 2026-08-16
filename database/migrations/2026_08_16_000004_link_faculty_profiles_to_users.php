<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained('users')->nullOnDelete();
            $table->string('administrative_role')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('administrative_role');
        });
    }
};
