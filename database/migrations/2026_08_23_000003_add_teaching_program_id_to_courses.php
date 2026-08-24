<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('courses', 'teaching_program_id')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->foreignId('teaching_program_id')->nullable()->after('teaching_department_id')
                    ->constrained('programs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('courses', 'teaching_program_id')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('teaching_program_id');
            });
        }
    }
};
