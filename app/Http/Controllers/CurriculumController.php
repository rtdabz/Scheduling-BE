<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Curriculum;
use App\Models\Course;

class CurriculumController extends Controller
{
    // Attach a course to a curriculum
    public function attachCourse(Request $request, Curriculum $curriculum)
    {
        $validated = $request->validate([
            'course_id'  => 'required|exists:courses,id',
            'year_level' => 'required|integer|between:1,4',
            'semester'   => 'required|integer|between:1,3',
        ]);

        $curriculum->courses()->syncWithoutDetaching([
            $validated['course_id'] => [
                'year_level' => $validated['year_level'],
                'semester'   => $validated['semester'],
            ]
        ]);

        return response()->json(['message' => 'Course attached successfully']);
    }

    // Remove a course from a curriculum
    public function detachCourse(Curriculum $curriculum, Course $course)
    {
        $curriculum->courses()->detach($course->id);
        return response()->json(['message' => 'Course removed successfully']);
    }

    // Get the full curriculum, grouped and totaled like the official form
    public function showWithCourses(Curriculum $curriculum)
    {
        $courses = $curriculum->courses()
            ->orderBy('pivot_year_level')
            ->orderBy('pivot_semester')
            ->get();

        $grouped = $courses->groupBy(fn($c) => $c->pivot->year_level . '-' . $c->pivot->semester)
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'year_level' => $first->pivot->year_level,
                    'semester'   => $first->pivot->semester,
                    'courses'    => $group->map(fn($c) => [
                        'code'       => $c->course_code,
                        'title'      => $c->course_name,
                        'lec_units'  => $c->lecture_hours,
                        'lab_units'  => $c->lab_hours,
                        'total_units'=> $c->units,
                    ])->values(),
                    'totals' => [
                        'lec' => $group->sum('lecture_hours'),
                        'lab' => $group->sum('lab_hours'),
                        'tu'  => $group->sum('units'),
                    ],
                ];
            })->values();

        return response()->json([
            'curriculum' => $curriculum->only(['id', 'code', 'effective_school_year', 'status']),
            'terms'      => $grouped,
        ]);
    }
}
