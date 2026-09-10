<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the course-category tables, which never carried working data.
 *
 * `course_category_mapping` was only ever filled by the migration that created
 * it, from the courses that happened to exist at that moment. Nothing in the
 * application ever wrote to it again -- no sync, no attach, no insert -- so
 * every course added afterwards was uncategorised, and on any database seeded
 * after that migration ran the table was simply empty.
 *
 * Nothing is lost by removing it. The three categories the scheduler actually
 * consulted each had a heuristic fallback that decided the same question
 * without the table: 'GEC' from the course code prefix, 'Laboratory' from
 * lab_hours and room_type_required, 'Field' from room_type_required, the NSTP
 * keywords and the per-department field-course settings. Those fallbacks were
 * doing all of the work already. The remaining two categories, 'Research' and
 * 'Other', were seeded but never read anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('course_category_mapping');
        Schema::dropIfExists('course_categories');
    }

    public function down(): void
    {
        Schema::create('course_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('course_category_mapping', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('course_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'category_id'], 'course_category_mapping_unique');
            $table->index('category_id');
        });

        // Restores the catalogue only. The mappings are not rebuilt: they were
        // derived from courses that existed when the original migration ran,
        // and that derivation is exactly what the code no longer depends on.
        $now = now();
        DB::table('course_categories')->insert([
            ['name' => 'GEC', 'description' => 'General Education Curriculum courses.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Laboratory', 'description' => 'Courses that require laboratory scheduling rules.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Field', 'description' => 'Courses that use field or activity-area scheduling rules.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Research', 'description' => 'Research, thesis, capstone, or similar courses.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Other', 'description' => 'Additional course classification.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
};
