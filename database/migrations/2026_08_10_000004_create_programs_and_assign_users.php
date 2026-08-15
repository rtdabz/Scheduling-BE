<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('cluster')->nullable();
            $table->string('code');
            $table->string('name');
            $table->timestamps();

            $table->unique(['department_id', 'code'], 'programs_department_code_unique');
            $table->index(['department_id', 'cluster']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('program_id')
                ->nullable()
                ->after('department_id')
                ->constrained('programs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('program_id');
        });

        Schema::dropIfExists('programs');
    }
};
