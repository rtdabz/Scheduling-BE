<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unsaved progress of the Generate Schedule wizard: one draft per user, department and
     * semester. It holds the wizard's inputs and the id of the last generation run, never a
     * timetable: generated rows stay on the generation run until the user saves them.
     */
    public function up(): void
    {
        Schema::create('schedule_generation_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->json('payload')->nullable();
            // Client-issued, monotonically increasing; older saves are refused.
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'department_id', 'semester_id'], 'schedule_generation_drafts_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_generation_drafts');
    }
};
