<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table): void {
            $table->foreignId('program_id')
                ->nullable()
                ->after('department_id')
                ->constrained('programs')
                ->nullOnDelete();
            $table->index(['department_id', 'program_id', 'term_id'], 'sections_department_program_term_index');
        });

        Schema::table('schedules', function (Blueprint $table): void {
            $table->foreignId('program_id')
                ->nullable()
                ->after('department_id')
                ->constrained('programs')
                ->nullOnDelete();
            $table->index(['department_id', 'program_id', 'term_id'], 'schedules_department_program_term_index');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropIndex('schedules_department_program_term_index');
            $table->dropConstrainedForeignId('program_id');
        });

        Schema::table('sections', function (Blueprint $table): void {
            $table->dropIndex('sections_department_program_term_index');
            $table->dropConstrainedForeignId('program_id');
        });
    }
};
