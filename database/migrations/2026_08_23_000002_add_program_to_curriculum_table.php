<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('curriculum') || Schema::hasColumn('curriculum', 'program_id')) {
            return;
        }

        Schema::table('curriculum', function (Blueprint $table): void {
            $table->foreignId('program_id')
                ->nullable()
                ->after('department_id')
                ->constrained('programs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('curriculum') && Schema::hasColumn('curriculum', 'program_id')) {
            Schema::table('curriculum', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('program_id');
            });
        }
    }
};
