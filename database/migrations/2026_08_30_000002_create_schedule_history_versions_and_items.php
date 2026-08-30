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
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('source', 40)->nullable();
            $table->text('reason')->nullable();
            $table->json('change_summary')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'term_id', 'created_at']);
        });

        Schema::create('schedule_history_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('history_version_id')->constrained('schedule_history_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('original_schedule_id')->nullable();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('snapshot_metadata')->nullable();
            $table->timestamps();
            $table->index('original_schedule_id');
        });

        Schema::table('scheduling_audit_logs', function (Blueprint $table): void {
            $table->foreignId('history_version_id')
                ->nullable()
                ->after('schedule_recommendation_id')
                ->constrained('schedule_history_versions')
                ->nullOnDelete();
        });

        Schema::table('schedule_recommendations', function (Blueprint $table): void {
            $table->foreignId('generation_run_id')
                ->nullable()
                ->after('department_id')
                ->constrained('schedule_generation_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_recommendations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('generation_run_id');
        });
        Schema::table('scheduling_audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('history_version_id');
        });
        Schema::dropIfExists('schedule_history_items');
        Schema::dropIfExists('schedule_history_versions');
    }
};
