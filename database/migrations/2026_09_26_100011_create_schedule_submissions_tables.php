<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->foreignId('parent_submission_id')->nullable()->constrained('schedule_submissions')->nullOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('status', 40)->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('dean_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dean_reviewed_at')->nullable();
            $table->foreignId('vpaa_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vpaa_reviewed_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('approval_override')->default(false);
            $table->string('approval_override_reason', 2000)->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'semester_id', 'revision_number'], 'schedule_submission_revision_unique');
            $table->index(['department_id', 'semester_id', 'status'], 'schedule_submission_scope_status_idx');
        });

        Schema::create('schedule_submission_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_submission_id')->constrained('schedule_submissions')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->string('state', 30)->default('included');
            $table->timestamps();
            $table->unique(['schedule_submission_id', 'section_id'], 'schedule_submission_section_unique');
            $table->index(['section_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_submission_sections');
        Schema::dropIfExists('schedule_submissions');
    }
};
