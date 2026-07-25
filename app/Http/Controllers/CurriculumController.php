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
        $query = Curriculum::with(['department'])->withCount('courses');

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
        $user = $request->user();
        $isPrivileged = in_array($user->role, ['vpaa', 'super_admin']);

        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:curricula,code',
            'program_id' => 'nullable|integer',
            'curriculum_version' => 'nullable|string|max:50',
            'academic_year' => 'nullable|string|max:20',
            'effective_school_year' => 'required|string|max:20',
            'status' => 'nullable|string|in:draft,active,archived',
            'description' => 'nullable|string',
        ];

        if ($isPrivileged) {
            $rules['department_id'] = 'nullable|exists:departments,id';
        }

        $validated = $request->validate($rules);
        $validated['status'] = $validated['status'] ?? 'draft';

        if (!$isPrivileged) {
            $validated['department_id'] = $user->department_id;
        }

        $curriculum = \DB::transaction(function () use ($validated) {
            if ($validated['status'] === 'active' && !empty($validated['department_id'])) {
                Curriculum::where('department_id', $validated['department_id'])
                    ->where('status', 'active')
                    ->update(['status' => 'draft']);
            }
            return Curriculum::create($validated);
        });

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
        $user = $request->user();
        $isPrivileged = in_array($user->role, ['vpaa', 'super_admin']);

        $rules = [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50|unique:curricula,code,' . $curriculum->id,
            'program_id' => 'nullable|integer',
            'curriculum_version' => 'nullable|string|max:50',
            'academic_year' => 'nullable|string|max:20',
            'effective_school_year' => 'sometimes|string|max:20',
            'status' => 'nullable|string|in:draft,active,archived',
            'description' => 'nullable|string',
        ];

        if ($isPrivileged) {
            $rules['department_id'] = 'nullable|exists:departments,id';
        }

        $validated = $request->validate($rules);

        if (!$isPrivileged) {
            unset($validated['department_id']);
        }

        \DB::transaction(function () use ($validated, $curriculum) {
            $newStatus = $validated['status'] ?? $curriculum->status;
            $deptId = isset($validated['department_id']) ? $validated['department_id'] : $curriculum->department_id;

            if ($newStatus === 'active' && $deptId) {
                Curriculum::where('department_id', $deptId)
                    ->where('id', '!=', $curriculum->id)
                    ->where('status', 'active')
                    ->update(['status' => 'draft']);
            }

            $curriculum->update($validated);
        });

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

        \DB::transaction(function () use ($validated, $curriculum) {
            if ($validated['status'] === 'active' && $curriculum->department_id) {
                Curriculum::where('department_id', $curriculum->department_id)
                    ->where('id', '!=', $curriculum->id)
                    ->where('status', 'active')
                    ->update(['status' => 'draft']);
            }

            $curriculum->update(['status' => $validated['status']]);
        });

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

    public function attachCoursesBatch(Request $request, Curriculum $curriculum)
    {
        $validated = $request->validate([
            'courses'              => 'required|array|min:1',
            'courses.*.course_id'  => 'required|integer|exists:courses,id',
            'courses.*.year_level' => 'required|integer|between:1,4',
            'courses.*.semester'   => 'required|integer|between:1,3',
        ]);

        $syncData = [];
        foreach ($validated['courses'] as $item) {
            $syncData[$item['course_id']] = [
                'year_level' => $item['year_level'],
                'semester'   => $item['semester'],
            ];
        }

        $curriculum->courses()->syncWithoutDetaching($syncData);

        return response()->json(['message' => count($syncData) . ' course(s) attached successfully']);
    }

    public function batchCreateAndAttachCourses(Request $request, Curriculum $curriculum)
    {
        $validated = $request->validate([
            'courses' => 'required|array|min:1',
            'courses.*.row_id' => 'required|string',
            'courses.*.course_code' => 'required|string',
            'courses.*.course_name' => 'required|string',
            'courses.*.course_category' => 'required|string|in:major,minor',
            'courses.*.lecture_hours' => 'required|integer|min:0',
            'courses.*.lab_hours' => 'required|integer|min:0',
            'courses.*.units' => 'required|integer|min:0',
            'courses.*.year_level' => 'required|integer|between:1,4',
            'courses.*.semester' => 'required|integer|between:1,3',
        ]);

        $results = [];

        foreach ($validated['courses'] as $item) {
            $rowId = $item['row_id'];
            $code = trim(preg_replace('/\s+/', ' ', strtoupper($item['course_code'])));
            $name = trim(preg_replace('/\s+/', ' ', ucwords(strtolower($item['course_name']))));
            $category = $item['course_category'];
            $lec = $item['lecture_hours'];
            $lab = $item['lab_hours'];
            $units = $item['units'];
            $yearLevel = $item['year_level'];
            $semester = $item['semester'];

            try {
                \DB::beginTransaction();

                // 1. Check if course already exists by course_code
                $course = \App\Models\Course::where('course_code', $code)->first();

                if (!$course) {
                    // Create course
                    $course = \App\Models\Course::create([
                        'course_code' => $code,
                        'course_name' => $name,
                        'lecture_hours' => $lec,
                        'lab_hours' => $lab,
                        'units' => $units,
                        'course_category' => $category,
                        'room_type_required' => $lab > 0 ? 'laboratory' : 'lecture',
                        'department_id' => $curriculum->department_id,
                        'status' => 'active',
                    ]);
                }

                // 2. Check if already attached to this curriculum
                $isAttached = $curriculum->courses()->where('courses.id', $course->id)->exists();

                if ($isAttached) {
                    $sameAttached = $curriculum->courses()
                        ->where('courses.id', $course->id)
                        ->wherePivot('year_level', $yearLevel)
                        ->wherePivot('semester', $semester)
                        ->exists();

                    if ($sameAttached) {
                        $results[] = [
                            'row_id' => $rowId,
                            'status' => 'success',
                            'course' => $course,
                            'message' => 'Course is already attached to this term.'
                        ];
                        \DB::commit();
                        continue;
                    } else {
                        throw new \Exception('Course code is already used in another term of this curriculum.');
                    }
                }

                // 3. Attach
                $curriculum->courses()->attach($course->id, [
                    'year_level' => $yearLevel,
                    'semester' => $semester,
                ]);

                \DB::commit();

                $results[] = [
                    'row_id' => $rowId,
                    'status' => 'success',
                    'course' => $course
                ];
            } catch (\Exception $e) {
                \DB::rollBack();
                $results[] = [
                    'row_id' => $rowId,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'results' => $results
        ]);
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
