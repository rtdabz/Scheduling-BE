<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Curriculum;
use App\Models\Course;
use App\Support\ApiCache;

class CurriculumController extends Controller
{
    public function index(Request $request)
    {
        $query = Curriculum::withCount('courses');

        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('academic_year') && $request->academic_year) {
            $query->where('academic_year', $request->academic_year);
        }

        $curricula = $query->orderBy('created_at', 'desc')->get();

        return response()->json($curricula);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:curricula,code',
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => 'nullable|integer',
            'curriculum_version' => 'nullable|string|max:50',
            'academic_year' => 'nullable|string|max:20',
            'effective_school_year' => 'required|string|max:20',
            'status' => 'nullable|string|in:draft,active,archived',
            'description' => 'nullable|string',
        ]);

        $validated['status'] = $validated['status'] ?? 'draft';

        $curriculum = Curriculum::create($validated);
        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curricula.index']);

        return response()->json($curriculum, 201);
    }

    public function show(Curriculum $curriculum)
    {
        $curriculum->loadCount('courses');
        $curriculum->load('department');

        return response()->json($curriculum);
    }

    public function update(Request $request, Curriculum $curriculum)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50|unique:curricula,code,' . $curriculum->id,
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => 'nullable|integer',
            'curriculum_version' => 'nullable|string|max:50',
            'academic_year' => 'nullable|string|max:20',
            'effective_school_year' => 'sometimes|string|max:20',
            'status' => 'nullable|string|in:draft,active,archived',
            'description' => 'nullable|string',
        ]);

        $curriculum->update($validated);
        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curricula.index']);

        return response()->json($curriculum);
    }

    public function destroy(Curriculum $curriculum)
    {
        $curriculum->delete();

        ApiCache::forgetGroups(['curricula.index']);

        return response()->json(['message' => 'Curriculum deleted successfully']);
    }

    public function duplicate(Curriculum $curriculum)
    {
        $newCurriculum = Curriculum::create([
            'name' => $curriculum->name . ' (Copy)',
            'code' => $curriculum->code . '-COPY-' . time(),
            'department_id' => $curriculum->department_id,
            'program_id' => $curriculum->program_id,
            'curriculum_version' => $curriculum->curriculum_version,
            'academic_year' => $curriculum->academic_year,
            'effective_school_year' => $curriculum->effective_school_year,
            'status' => 'draft',
            'description' => $curriculum->description,
        ]);

        $courses = $curriculum->courses()->get();
        if ($courses->isNotEmpty()) {
            $attachData = [];
            foreach ($courses as $course) {
                $attachData[$course->id] = [
                    'year_level' => $course->pivot->year_level,
                    'semester' => $course->pivot->semester,
                ];
            }
            $newCurriculum->courses()->attach($attachData);
        }

        $newCurriculum->loadCount('courses');

        ApiCache::forgetGroups(['curricula.index']);

        return response()->json($newCurriculum, 201);
    }

    public function updateStatus(Request $request, Curriculum $curriculum)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:draft,active,archived',
        ]);

        if ($validated['status'] === 'active' && $curriculum->department_id) {
            Curriculum::where('department_id', $curriculum->department_id)
                ->where('id', '!=', $curriculum->id)
                ->where('status', 'active')
                ->update(['status' => 'draft']);
        }

        $curriculum->update(['status' => $validated['status']]);
        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curricula.index', 'initial.data']);

        return response()->json($curriculum);
    }

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

    public function detachCourse(Curriculum $curriculum, Course $course)
    {
        $curriculum->courses()->detach($course->id);
        return response()->json(['message' => 'Course removed successfully']);
    }

    public function showWithCourses(Curriculum $curriculum)
    {
        $curriculum->loadCount('courses');
        $curriculum->load('department');

        $courses = $curriculum->courses()
            ->orderBy('curriculum_course.year_level')
            ->orderBy('curriculum_course.semester')
            ->get();

        $grouped = $courses->groupBy(fn($c) => $c->pivot->year_level . '-' . $c->pivot->semester)
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'year_level' => (int)$first->pivot->year_level,
                    'semester'   => (int)$first->pivot->semester,
                    'courses'    => $group->map(fn($c) => [
                        'id'          => $c->id,
                        'code'       => $c->course_code,
                        'title'      => $c->course_name,
                        'category'   => $c->course_category,
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
            'curriculum' => [
                'id' => $curriculum->id,
                'name' => $curriculum->name,
                'code' => $curriculum->code,
                'department_id' => $curriculum->department_id,
                'department' => $curriculum->department,
                'program_id' => $curriculum->program_id,
                'curriculum_version' => $curriculum->curriculum_version,
                'academic_year' => $curriculum->academic_year,
                'effective_school_year' => $curriculum->effective_school_year,
                'status' => $curriculum->status,
                'description' => $curriculum->description,
                'courses_count' => $curriculum->courses_count,
            ],
            'terms'      => $grouped,
        ]);
    }
}
