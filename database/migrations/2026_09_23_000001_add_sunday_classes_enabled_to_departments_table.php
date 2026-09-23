<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sunday is an overflow day: only a department with more sections than the
 * week holds, or laboratories that need the extra time, schedules on it. The
 * department secretary opens it; every other department stays Monday-Saturday.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('departments', 'sunday_classes_enabled')) {
            return;
        }

        Schema::table('departments', function (Blueprint $table): void {
            $table->boolean('sunday_classes_enabled')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('departments', 'sunday_classes_enabled')) {
            return;
        }

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('sunday_classes_enabled');
        });
    }
};
