<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseTeachingAssignment;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CourseTeachingAssignmentController extends Controller
{
    public function index(): JsonResponse
    {
        $relations = [
            'course:id,course_code,course_name,course_category,department_id',
            'department:id,department_code,department_name',
        ];

        if ($this->hasCourseCategoryTables()) {
            $relations[] = 'course.categories';
        }

        $assignments = CourseTeachingAssignment::query()
            ->with($relations)
            ->orderBy('course_id')
            ->get();

        return response()->json([
            'data' => $assignments,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $assignment = CourseTeachingAssignment::query()->updateOrCreate(
            ['course_id' => (int) $validated['course_id']],
            ['department_id' => (int) $validated['department_id']],
        );

        SchedulingPolicy::clearCourseTeachingAssignmentCache();
        SchedulingPolicy::clearCourseCategoryCache();

        $relations = [
            'course:id,course_code,course_name,course_category,department_id',
            'department:id,department_code,department_name',
        ];

        if ($this->hasCourseCategoryTables()) {
            $relations[] = 'course.categories';
        }

        return response()->json([
            'message' => 'Course teaching department assignment saved.',
            'data' => $assignment->load($relations),
        ], 201);
    }

    public function destroy(Course $course): JsonResponse
    {
        CourseTeachingAssignment::query()
            ->where('course_id', $course->id)
            ->delete();

        SchedulingPolicy::clearCourseTeachingAssignmentCache();
        SchedulingPolicy::clearCourseCategoryCache();

        return response()->json([
            'message' => 'Course teaching department assignment removed.',
        ]);
    }

    private function hasCourseCategoryTables(): bool
    {
        return Schema::hasTable('course_categories')
            && Schema::hasTable('course_category_mapping');
    }
}
