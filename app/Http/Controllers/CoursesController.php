<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class CoursesController extends Controller
{
    public function index(Request $request)
    {
        $deptId = $request->query('department_id');
        $bypassActiveCurriculum = $request->query('all') === 'true' || $request->query('catalog') === 'true';

        if (!$bypassActiveCurriculum) {
            // 1. Query for active curricula scoped to the department if requested
            $curriculaQuery = \App\Models\Curriculum::where('status', 'active');

            if ($deptId) {
                $curriculaQuery->where(function ($q) use ($deptId) {
                    $q->where('department_id', $deptId)
                      ->orWhereNull('department_id');
                });
            }

            $activeCurriculaIds = $curriculaQuery->pluck('id');

            if ($activeCurriculaIds->isNotEmpty()) {
                // 2. Fetch all courses belonging to these active curricula
                $courses = Course::with('department')
                    ->whereHas('curricula', function ($q) use ($activeCurriculaIds) {
                        $q->whereIn('curricula.id', $activeCurriculaIds);
                    })
                    ->when($request->has('status') && $request->query('status'), function ($q) use ($request) {
                        $q->where('status', $request->query('status'));
                    })
                    ->get();

                // 3. Load pivot data for year_level and semester mapping
                $pivotData = \DB::table('curriculum_course')
                    ->whereIn('curriculum_id', $activeCurriculaIds)
                    ->get();

                $pivotMap = [];
                foreach ($pivotData as $p) {
                    if (!isset($pivotMap[$p->course_id])) {
                        $pivotMap[$p->course_id] = $p;
                    }
                }

                $courses->transform(function ($course) use ($pivotMap) {
                    if (isset($pivotMap[$course->id])) {
                        $p = $pivotMap[$course->id];
                        $course->year_level = (string)$p->year_level;
                        $course->semester = $p->semester == 1 ? '1st' : ($p->semester == 2 ? '2nd' : 'summer');
                    }
                    return $course;
                });

                // Sort logically: Year Level ASC, Semester ASC, Course Code ASC
                $courses = $courses->sortBy([
                    ['year_level', 'asc'],
                    ['semester', 'asc'],
                    ['course_code', 'asc'],
                ])->values();

                return response()->json($courses);
            }
        }

        // Fallback: If no active curriculum exists, return courses table records
        $query = Course::with('department');

        if ($deptId) {
            $query->where('department_id', $deptId);
        }

        if ($request->has('status') && $request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->orderBy('course_code')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_code' => 'required|string|unique:courses,course_code',
            'course_name' => 'required|string',
            'lecture_hours' => 'required|integer|min:0',
            'lab_hours' => 'required|integer|min:0',
            'units' => 'required|integer|min:0',
            'course_category' => 'required|in:major,minor',
            'room_type_required' => 'required|in:lecture,laboratory,field,online',
            'year_level' => 'nullable|in:1,2,3,4',
            'semester' => 'nullable|in:1st,2nd,summer',
            'department_id' => 'nullable|exists:departments,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        $course = Course::create($validated);
        return response()->json($course->load('department'), 201);
    }

    public function show(Course $course)
    {
        return response()->json($course->load('department'));
    }

    public function update(Request $request, Course $course)
    {
        $validated = $request->validate([
            'course_code' => 'sometimes|required|string|unique:courses,course_code,' . $course->id,
            'course_name' => 'sometimes|required|string',
            'lecture_hours' => 'sometimes|required|integer|min:0',
            'lab_hours' => 'sometimes|required|integer|min:0',
            'units' => 'sometimes|required|integer|min:0',
            'course_category' => 'sometimes|required|in:major,minor',
            'room_type_required' => 'sometimes|required|in:lecture,laboratory,field,online',
            'year_level' => 'sometimes|required|in:1,2,3,4',
            'semester' => 'sometimes|required|in:1st,2nd,summer',
            'department_id' => 'nullable|exists:departments,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        $course->update($validated);
        return response()->json($course->load('department'));
    }

    public function destroy(Course $course)
    {
        $course->delete();
        return response()->json(['message' => 'Course deleted successfully']);
    }
}
