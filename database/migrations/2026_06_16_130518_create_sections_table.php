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
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
                $table->string('section_name');
                $table->enum('year_level', ['1', '2', '3', '4']);
                $table->enum('semester', ['1st', '2nd', 'summer']);
                $table->integer('number_of_students')->default(0);
                $table->foreignId('department_id')
                    ->constrained('departments')
                    ->cascadeOnDelete();
                $table->foreignId('term_id')
                    ->constrained('terms')
                    ->cascadeOnDelete();
                $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
