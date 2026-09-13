<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Administrative designations an instructor may hold -- Dean, Program
     * Chairperson, Laboratory Head and whatever else the institution defines.
     *
     * Deliberately a table rather than an enum: the list is institution data
     * that grows, so it is maintained through CRUD instead of a migration.
     * `deload_units` is the whole point of the record -- holding the post
     * subtracts that many units from the instructor's Basic Load, which
     * SchedulingPolicy::facultyBasicLoad() already computes as
     * `max_units - deload_units`.
     */
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->unsignedInteger('deload_units')->default(0);
            $table->string('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
