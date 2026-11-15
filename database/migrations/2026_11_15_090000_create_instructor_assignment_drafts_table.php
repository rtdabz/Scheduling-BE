<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unsaved progress of the Assign Instructors list: one draft per user, department and
     * semester. It only holds what the user queued; instructors are assigned when the user
     * confirms, through the existing assignment rules.
     */
    public function up(): void
    {
        Schema::create('instructor_assignment_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->json('payload')->nullable();
            // Client-issued, monotonically increasing. A save carrying an older revision than
            // the stored one is refused, so delayed or retried requests never roll progress back.
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'department_id', 'semester_id'], 'instructor_assignment_drafts_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_assignment_drafts');
    }
};
