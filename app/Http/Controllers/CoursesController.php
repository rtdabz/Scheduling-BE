<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class CoursesController extends Controller
{
    public function index(Request $request)
    {
        $query = Course::with('department');

        if ($request->has('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        if ($request->has('status')) {
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
