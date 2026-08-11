<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_categories') || !Schema::hasTable('course_category_mapping')) {
            return;
        }

        $gecCategoryId = DB::table('course_categories')
            ->where('name', 'GEC')
            ->value('id');

        if (!$gecCategoryId) {
            return;
        }

        $geeCourseIds = DB::table('courses')
            ->select(['id', 'course_code'])
            ->where('course_category', 'minor')
            ->get()
            ->filter(function ($course): bool {
                $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $course->course_code) ?? '');

                return str_starts_with($code, 'GEE');
            })
            ->pluck('id')
            ->all();

        if ($geeCourseIds === []) {
            return;
        }

        DB::table('course_category_mapping')
            ->where('category_id', $gecCategoryId)
            ->whereIn('course_id', $geeCourseIds)
            ->delete();
    }

    public function down(): void
    {
        // GEE courses should remain distinct from GEC courses.
    }
};
