<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->string('status', 20)->index();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            // MySQL limits identifier names to 64 characters; the generated
            // Laravel name for this composite index exceeds that limit.
            $table->index(
                ['department_id', 'term_id', 'year_level', 'created_at'],
                'sgr_dept_term_year_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_generation_runs');
    }
};
