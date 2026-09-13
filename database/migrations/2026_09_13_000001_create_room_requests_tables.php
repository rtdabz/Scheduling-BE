<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A department asking the VPAA to use another department's room during
 * specific weekly windows of one term.
 *
 * An approved request is a grant: the requesting department may place classes
 * in the room inside those windows, and nowhere else in the week. The owner
 * keeps the room; ordinary room-conflict rules stop the two from overlapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->foreignId('requesting_department_id')->constrained('departments')->cascadeOnDelete();
            // Captured when the request is made so a later reassignment of the
            // room does not rewrite whom the request was addressed to.
            $table->foreignId('owner_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'revoked'])->default('pending');
            $table->text('purpose')->nullable();
            $table->text('review_remarks')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'term_id', 'status']);
            $table->index(['requesting_department_id', 'term_id', 'status']);
        });

        // One row per weekday, the same shape as schedules.day.
        Schema::create('room_request_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_request_id')->constrained('room_requests')->cascadeOnDelete();
            $table->string('day', 16);
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
