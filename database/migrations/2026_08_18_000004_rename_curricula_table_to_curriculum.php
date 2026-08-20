<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename the `curricula` table to `curriculum` and drop the unused
     * `curriculum_version` / `academic_year` attributes.
     */
    public function up(): void
    {
        if (Schema::hasTable('curricula') && !Schema::hasTable('curriculum')) {
            Schema::rename('curricula', 'curriculum');
        }

        if (!Schema::hasTable('curriculum')) {
            return;
        }

        Schema::table('curriculum', function (Blueprint $table) {
            foreach (['curriculum_version', 'academic_year'] as $column) {
                if (Schema::hasColumn('curriculum', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('curriculum')) {
            Schema::table('curriculum', function (Blueprint $table) {
                if (!Schema::hasColumn('curriculum', 'curriculum_version')) {
                    $table->string('curriculum_version')->nullable()->after('code');
                }
                if (!Schema::hasColumn('curriculum', 'academic_year')) {
                    $table->string('academic_year')->nullable()->after('curriculum_version');
                }
            });

            if (!Schema::hasTable('curricula')) {
                Schema::rename('curriculum', 'curricula');
            }
        }
    }
};
