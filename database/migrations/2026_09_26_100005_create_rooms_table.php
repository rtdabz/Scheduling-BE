<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('room_code')->unique();
            $table->string('building')->nullable();
            $table->enum('room_type', ['lecture', 'laboratory', 'online', 'field']);
            $table->boolean('allow_lecture_usage')->default(false);
            $table->enum('status', ['available', 'not available'])->default('available');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('home_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
