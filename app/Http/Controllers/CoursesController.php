<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;
use App\Services\Scheduling\ScheduleAuthorizationService;

class CoursesController extends Controller
{
    public function __construct(private readonly ScheduleAuthorizationService $authorization) {}

    public function index(Request $request)
    {
        if ($this->authorization->rejectsRequestedDepartment($request, $request->query('department_id'))) return response()->json(['message' => 'You can only view courses for your department.'], 403);
        $deptId = $this->authorization->requestedDepartment($request, $request->query('department_id'));
        $bypassActiveCurriculum = $request->query('all') === 'true' || $request->query('catalog') === 'true';

        if (!$bypassActiveCurriculum) {
            // 1. Query for active curriculum records scoped to the department if requested
            $curriculumQuery = \App\Models\Curriculum::where('status', 'active');

            if ($deptId) {
                $curriculumQuery->where('department_id', $deptId);
            }

            $activeCurriculumIds = $curriculumQuery->pluck('id');

            if ($activeCurriculumIds->isNotEmpty()) {
                // 2. Fetch all courses belonging to these active curriculum records
                $courses = Course::with('department')
                    ->whereHas('curriculum', function ($q) use ($activeCurriculumIds) {
                        $q->whereIn('curriculum.id', $activeCurriculumIds);
                    })
                    ->when($deptId, function ($q) use ($deptId) {
                        $q->where(function ($courseQuery) use ($deptId) {
                            $courseQuery->whereNull('department_id')
                                ->orWhere('department_id', $deptId);
                        });
                    })
                    ->when($request->has('status') && $request->query('status'), function ($q) use ($request) {
                        $q->where('status', $request->query('status'));
                    })
                    ->get();

                // 3. Load pivot data for year_level and semester mapping
                $pivotData = \DB::table('curriculum_course')
                    ->whereIn('curriculum_id', $activeCurriculumIds)
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

                // Sort logically: Year Level ASC, Semester ASC, Category (Major first), Course Code ASC
                $courses = $courses->sort(function ($a, $b) {
                    $yA = (int) ($a->year_level ?? 0);
                    $yB = (int) ($b->year_level ?? 0);
                    if ($yA !== $yB) return $yA <=> $yB;

                    $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
                    $sA = $semOrder[$a->semester ?? ''] ?? 99;
                    $sB = $semOrder[$b->semester ?? ''] ?? 99;
                    if ($sA !== $sB) return $sA <=> $sB;

                    $catA = strtolower($a->course_category ?? '') === 'major' ? 1 : 2;
                    $catB = strtolower($b->course_category ?? '') === 'major' ? 1 : 2;
                    if ($catA !== $catB) return $catA <=> $catB;

                    return strcmp($a->course_code ?? '', $b->course_code ?? '');
                })->values();

                return response()->json($courses);
            } else {
                return response()->json([]);
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

        $courses = $query->get()->sort(function ($a, $b) {
            $yA = (int) ($a->year_level ?? 0);
            $yB = (int) ($b->year_level ?? 0);
            if ($yA !== $yB) return $yA <=> $yB;

            $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
            $sA = $semOrder[$a->semester ?? ''] ?? 99;
            $sB = $semOrder[$b->semester ?? ''] ?? 99;
            if ($sA !== $sB) return $sA <=> $sB;

            $catA = strtolower($a->course_category ?? '') === 'major' ? 1 : 2;
            $catB = strtolower($b->course_category ?? '') === 'major' ? 1 : 2;
            if ($catA !== $catB) return $catA <=> $catB;

            return strcmp($a->course_code ?? '', $b->course_code ?? '');
        })->values();

        return response()->json($courses);
    }

    public function store(Request $request)
    {
        if ($request->has('course_code')) {
            $request->merge([
                'course_code' => $this->normalizeCourseCode($request->input('course_code')),
            ]);
        }

        $validated = $request->validate([
            'course_code' => [
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('courses', 'course_code')->where(function ($query) use ($request) {
                    return $query->where('department_id', $request->department_id);
                })
            ],
            'course_name' => 'required|string',
            'lecture_hours' => 'required|integer|min:0',
            'lab_hours' => 'required|integer|min:0',
            'units' => 'required|integer|min:0',
            'course_category' => 'required|in:major,minor',
            'room_type_required' => 'required|in:lecture,laboratory,field,online',
            'year_level' => 'nullable|in:1,2,3,4',
            'semester' => 'nullable|in:1st,2nd,summer',
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => $this->programRule($request->input('department_id')),
            'status' => 'nullable|in:active,inactive',
        ]);

        $requestedDepartmentId = $validated['department_id'] ?? null;
        if ($requestedDepartmentId !== null && ! $this->authorization->payloadBelongsToDepartment($request, (int) $requestedDepartmentId)) {
            return response()->json(['message' => 'You can only manage courses for your department.'], 403);
        }

        $validated = $this->clearProgramForNonMajor($validated, $validated['course_category'] ?? null);

        $course = Course::create($validated);
        return response()->json($course->load(['department', 'program']), 201);
    }

    public function show(Request $request, Course $course)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $course->department_id)) return response()->json(['message' => 'Forbidden.'], 403);
        return response()->json($course->load(['department', 'program']));
    }

    public function update(Request $request, Course $course)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $course->department_id)) return response()->json(['message' => 'Forbidden.'], 403);
        if ($request->has('course_code')) {
            $request->merge([
                'course_code' => $this->normalizeCourseCode($request->input('course_code')),
            ]);
        }

        $validated = $request->validate([
            'course_code' => [
                'sometimes',
                'required',
                'string',
                \Illuminate\Validation\Rule::unique('courses', 'course_code')
                    ->ignore($course->id)
                    ->where(function ($query) use ($request, $course) {
                        $deptId = $request->has('department_id') ? $request->department_id : $course->department_id;
                        return $query->where('department_id', $deptId);
                    })
            ],
            'course_name' => 'sometimes|required|string',
            'lecture_hours' => 'sometimes|required|integer|min:0',
            'lab_hours' => 'sometimes|required|integer|min:0',
            'units' => 'sometimes|required|integer|min:0',
            'course_category' => 'sometimes|required|in:major,minor',
            'room_type_required' => 'sometimes|required|in:lecture,laboratory,field,online',
            'year_level' => 'sometimes|required|in:1,2,3,4',
            'semester' => 'sometimes|required|in:1st,2nd,summer',
            'department_id' => 'nullable|exists:departments,id',
            'program_id' => $this->programRule(
                $request->has('department_id') ? $request->input('department_id') : $course->department_id
            ),
            'status' => 'nullable|in:active,inactive',
        ]);

        $validated = $this->clearProgramForNonMajor(
            $validated,
            $validated['course_category'] ?? $course->course_category,
        );

        $course->update($validated);

        return response()->json($course->load(['department', 'program']));
    }

    /**
     * The program restriction is for majors only: a minor or service course is
     * taught across programs, so it never carries one. Clearing it here also means
     * turning a major into a minor drops the program it used to be tied to.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function clearProgramForNonMajor(array $validated, mixed $category): array
    {
        $isMajor = strtolower(trim((string) ($category ?? 'major'))) === 'major';

        if (! $isMajor) {
            $validated['program_id'] = null;
        }

        return $validated;
    }

    /**
     * A major's program decides which instructors may teach it, so the program has
     * to belong to the department that offers the course.
     *
     * @return array<int, mixed>
     */
    private function programRule(mixed $departmentId): array
    {
        if ($departmentId === null || $departmentId === '') {
            return ['nullable', 'integer', 'exists:programs,id'];
        }

        return [
            'nullable',
            'integer',
            \Illuminate\Validation\Rule::exists('programs', 'id')->where(
                fn ($query) => $query->where('department_id', (int) $departmentId),
            ),
        ];
    }

    public function destroy(Request $request, Course $course)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $course->department_id)) return response()->json(['message' => 'Forbidden.'], 403);
        $course->delete();
        return response()->json(['message' => 'Course archived successfully']);
    }

    private function normalizeCourseCode(mixed $courseCode): string
    {
        return trim(preg_replace('/\s+/', ' ', strtoupper((string) $courseCode)));
    }
}
