<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained('faculties')->nullOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->enum('day', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('mode', ['on-site', 'online', 'field'])->default('on-site');
            $table->boolean('is_hybrid')->default(false);
            $table->string('preferred_pattern', 20)->nullable();
            
            //Approval Workflow
            $table->enum('status', [
                'draft',
                'completed',
                'submitted',
                'approved_by_dean',
                'rejected_by_dean',
                'approved',
                'faculty_assignment',
                'finalized',
                'rejected'
            ])->default('draft');

            $table->text('rejection_reason')->nullable();

            // Dean review tracking
            $table->foreignId('reviewed_by_dean')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at_dean')->nullable();

            // VPAA approval tracking
            $table->foreignId('approved_by_vpaa')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at_vpaa')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
