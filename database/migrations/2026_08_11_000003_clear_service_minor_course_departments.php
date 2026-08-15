<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('courses')
            ->select(['id', 'course_code', 'course_category'])
            ->whereNotNull('department_id')
            ->where('course_category', 'minor')
            ->orderBy('id')
            ->chunkById(100, function ($courses): void {
                foreach ($courses as $course) {
                    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $course->course_code) ?? '');

                    if (str_starts_with($code, 'GEC') || str_starts_with($code, 'GEE')) {
                        DB::table('courses')
                            ->where('id', $course->id)
                            ->update(['department_id' => null]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Previous department ownership cannot be inferred safely after this cleanup.
    }
};
