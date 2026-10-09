<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('schedules', 'faculty_conflict_override')) {
            Schema::table('schedules', function (Blueprint $table): void {
                $table->dropColumn('faculty_conflict_override');
            });
        }

        if (Schema::hasColumn('faculties', 'probono_units')) {
            Schema::table('faculties', function (Blueprint $table): void {
                $table->dropColumn('probono_units');
            });
        }
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->boolean('faculty_conflict_override')->default(false)->after('faculty_assignment_done');
        });

        Schema::table('faculties', function (Blueprint $table): void {
            $table->integer('probono_units')->default(0)->after('deload_units');
        });
    }
};
