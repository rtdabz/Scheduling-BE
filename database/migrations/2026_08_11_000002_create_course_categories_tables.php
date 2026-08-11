<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

        $now = now();
        $categories = [
            ['name' => 'GEC', 'description' => 'General Education Curriculum courses.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Laboratory', 'description' => 'Courses that require laboratory scheduling rules.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Field', 'description' => 'Courses that use field or activity-area scheduling rules.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Research', 'description' => 'Research, thesis, capstone, or similar courses.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Other', 'description' => 'Additional course classification.', 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('course_categories')->insert($categories);

        $categoryIds = DB::table('course_categories')->pluck('id', 'name');
        $courses = DB::table('courses')->get(['id', 'course_code', 'course_name', 'lab_hours', 'room_type_required']);
        $fieldCodes = Schema::hasTable('field_course_settings')
            ? DB::table('field_course_settings')->whereNotNull('course_code')->pluck('course_code')->map(
                static fn ($code): string => strtoupper(trim(preg_replace('/\s+/', ' ', (string) $code) ?? (string) $code))
            )->flip()
            : collect();

        $rows = [];
        foreach ($courses as $course) {
            $code = strtoupper(trim((string) $course->course_code));
            $normalizedCode = preg_replace('/[^A-Z0-9]/', '', $code) ?? $code;
            $name = strtoupper((string) $course->course_name);
            $normalizedFieldCode = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $course->course_code) ?? (string) $course->course_code));

            $names = [];
            if (str_starts_with($normalizedCode, 'GEC')) {
                $names[] = 'GEC';
            }

            if ((int) $course->lab_hours > 0 || (string) $course->room_type_required === 'laboratory') {
                $names[] = 'Laboratory';
            }

            if ((string) $course->room_type_required === 'field' || $fieldCodes->has($normalizedFieldCode)) {
                $names[] = 'Field';
            }

            if (
                str_contains($name, 'RESEARCH')
                || str_contains($name, 'THESIS')
                || str_contains($name, 'CAPSTONE')
            ) {
                $names[] = 'Research';
            }

            foreach (array_unique($names) as $categoryName) {
                if (!isset($categoryIds[$categoryName])) {
                    continue;
                }

                $rows[] = [
                    'course_id' => (int) $course->id,
                    'category_id' => (int) $categoryIds[$categoryName],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('course_category_mapping')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_category_mapping');
        Schema::dropIfExists('course_categories');
    }
};
