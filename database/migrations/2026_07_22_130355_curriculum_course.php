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
        Schema::create('curriculum_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->tinyInteger('year_level')->unsigned();
            $table->tinyInteger('semester')->unsigned();
            $table->timestamps();

            $table->unique(['curriculum_id', 'course_id']); // prevent duplicate course in same curriculum
            $table->index(['curriculum_id', 'year_level', 'semester'], 'curriculum_course_term_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curriculum_course');
        Schema::dropIfExists('curriculum_subject');
    }
};
