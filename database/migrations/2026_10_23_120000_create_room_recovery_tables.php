<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_recovery_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms');
            $table->foreignId('semester_id')->constrained('semesters');
            $table->foreignId('department_id')->constrained('departments');
            $table->string('trigger');
            $table->text('reason');
            $table->json('original_rows');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['department_id', 'semester_id']);
        });
        Schema::create('room_recovery_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('semester_id')->constrained('semesters');
            $table->foreignId('department_id')->constrained('departments');
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->json('applied_groups')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_recovery_plans');
        Schema::dropIfExists('room_recovery_cases');
    }
};
