<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_history_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
            $table->string('academic_year', 50)->nullable();
            $table->string('semester', 30)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('source', 40)->nullable();
            $table->text('reason')->nullable();
            $table->json('change_summary')->nullable();
            $table->timestamps();
            $table->index(
                ['department_id', 'semester_id', 'created_at'],
                'schedule_history_versions_department_id_term_id_created_at_index'
            );
        });

        Schema::create('schedule_history_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('history_version_id')->constrained('schedule_history_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('original_schedule_id')->nullable()->index();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('snapshot_metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('scheduling_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('schedule_recommendation_id')->nullable()->constrained('schedule_recommendations')->nullOnDelete();
            $table->foreignId('history_version_id')->nullable()->constrained('schedule_history_versions')->nullOnDelete();
            $table->foreignId('schedule_submission_id')->nullable()->constrained('schedule_submissions')->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('action', 80);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'id'], 'scheduling_audit_created_id_index');
            $table->index(['action', 'created_at'], 'scheduling_audit_action_created_index');
            $table->index(['department_id', 'semester_id', 'created_at'], 'scheduling_audit_scope_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_audit_logs');
        Schema::dropIfExists('schedule_history_items');
        Schema::dropIfExists('schedule_history_versions');
    }
};
