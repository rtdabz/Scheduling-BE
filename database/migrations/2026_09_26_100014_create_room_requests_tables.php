<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function up(): void
    {
        Schema::create('room_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->foreignId('requesting_department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('owner_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'revoked'])->default('pending');
            $table->text('purpose')->nullable();
            $table->text('review_remarks')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'semester_id', 'status'], 'room_requests_room_id_term_id_status_index');
            $table->index(
                ['requesting_department_id', 'semester_id', 'status'],
                'room_requests_requesting_department_id_term_id_status_index'
            );
        });

        Schema::create('room_request_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_request_id')->constrained('room_requests')->cascadeOnDelete();
            $table->enum('day', self::DAYS);
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
            $table->index(['room_request_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_request_windows');
        Schema::dropIfExists('room_requests');
    }
};
