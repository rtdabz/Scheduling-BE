<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->foreignId('term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('snapshot');
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'term_id', 'created_at']);
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_histories');
    }
};
