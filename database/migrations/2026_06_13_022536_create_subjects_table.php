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
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('subject_code')->unique();
            $table->string('subject_name');
            $table->integer('lecture_hours')->default(0);
            $table->integer('lab_hours')->default(0);
            $table->integer('units')->default(0);
            $table->enum('subject_category', ['major', 'minor']);
            $table->enum('room_type_required', ['lecture', 'laboratory', 'field', 'online'])->default('lecture');
            $table->enum('year_level', ['1', '2', '3', '4']);
            $table->enum('semester', ['1st', '2nd', 'summer']);
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
