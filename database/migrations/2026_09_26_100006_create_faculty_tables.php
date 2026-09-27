<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedInteger('deload_units')->default(0);
            $table->string('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('faculties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('administrative_role')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('suffix', 10)->nullable();
            $table->enum('employment_type', ['full-time', 'part-time']);
            $table->integer('max_units')->default(21);
            $table->integer('overload_units')->default(0);
            $table->integer('deload_units')->default(0);
            $table->integer('probono_units')->default(0);
            $table->longText('profile_picture')->nullable();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['department_id', 'status'], 'faculties_department_status_index');
        });

        Schema::create('designation_faculty', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();
            $table->foreignId('designation_id')->constrained('designations')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['faculty_id', 'designation_id']);
        });

        Schema::create('faculty_availabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();
            $table->enum('day', self::DAYS);
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_availabilities');
        Schema::dropIfExists('designation_faculty');
        Schema::dropIfExists('faculties');
        Schema::dropIfExists('designations');
    }
};
