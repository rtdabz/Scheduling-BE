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

        $course = Course::query()->findOrFail((int) $validated['course_id']);
        $departmentId = (int) $validated['department_id'];

        // Teaching assignments exist to delegate service and minor courses. A
        // major is taught by the department that offers it, so it cannot be
        // pointed elsewhere — the rule engine would refuse every instructor the
        // assignment implied.
        if (
            SchedulingPolicy::isMajorCourse($course)
            && $course->department_id !== null
            && (int) $course->department_id !== $departmentId
        ) {
            return response()->json([
                'message' => 'A major course can only be taught by the department that offers it, so it cannot be assigned to another department.',
            ], 422);
        }

        $assignment = CourseTeachingAssignment::query()->updateOrCreate(
            ['course_id' => (int) $validated['course_id']],
            ['department_id' => $departmentId],
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
