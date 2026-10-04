<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The program that handed a course over to its teaching program. A shared course
 * such as GEC 1 belongs to a college but to no program, so without this the
 * receiving side could only name the college ("COED") rather than "BSED-English".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('teaching_source_program_id')
                ->nullable()
                ->after('teaching_program_id')
                ->constrained('programs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('teaching_source_program_id');
        });
    }
};
