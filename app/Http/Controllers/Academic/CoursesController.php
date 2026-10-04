<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Schedule;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Submission\RevisionChangeRecorder;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CoursesController extends Controller
{
    public function __construct(
        private readonly ScheduleAuthorizationService $authorization,
        private readonly RevisionChangeRecorder $revisionChanges,
    ) {}

    public function index(Request $request)
    {
        if ($this->authorization->rejectsRequestedDepartment($request, $request->query('department_id'))) {
            return response()->json(['message' => 'You can only view courses for your department.'], 403);
        }
        $deptId = $this->authorization->requestedDepartment($request, $request->query('department_id'));
        $bypassActiveCurriculum = $request->query('all') === 'true' || $request->query('catalog') === 'true';
        $curriculumId = $request->query('curriculum_id') !== null
            ? (int) $request->query('curriculum_id')
            : null;
        if ($curriculumId !== null
            && ! $this->authorization->curriculumBelongsToProgram($request, Curriculum::query()->whereKey($curriculumId)->value('program_id'))) {
            return response()->json(['message' => 'You can only view your own program curriculum.'], 403);
        }

        $cacheKey = ApiCache::key('courses.index', [
            'department_id' => $deptId,
            'curriculum_id' => $curriculumId,
            'all' => $bypassActiveCurriculum,
            'status' => $request->query('status'),
        ]);

        return response()->json(Cache::remember($cacheKey, ApiCache::LOOKUP_TTL_SECONDS, function () use ($request, $deptId, $bypassActiveCurriculum, $curriculumId) {
            if (! $bypassActiveCurriculum) {
                $curriculumQuery = Curriculum::where('status', 'active');

                if ($deptId) {
                    $curriculumQuery->where('department_id', $deptId);
                }

                if ($curriculumId !== null) {
                    $curriculumQuery->where('id', $curriculumId);
                }

                $activeCurriculumIds = $curriculumQuery->pluck('id');

                if ($activeCurriculumIds->isNotEmpty()) {
                    $courses = Course::with('department')
                        ->whereHas('curriculum', function ($q) use ($activeCurriculumIds) {
                            $q->whereIn('curriculum.id', $activeCurriculumIds);
                        })
                        ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
                        ->when($request->has('status') && $request->query('status'), function ($q) use ($request) {
                            $q->where('status', $request->query('status'));
                        })
                        ->get();

                    $pivotData = DB::table('curriculum_course')
                        ->join('curriculum', 'curriculum.id', '=', 'curriculum_course.curriculum_id')
                        ->whereIn('curriculum_course.curriculum_id', $activeCurriculumIds)
                        ->orderByDesc('curriculum.effective_school_year')
                        ->orderByDesc('curriculum_course.curriculum_id')
                        ->get([
                            'curriculum_course.course_id',
                            'curriculum_course.curriculum_id',
                            'curriculum_course.year_level',
                            'curriculum_course.semester',
                        ]);

                    $placements = [];
                    foreach ($pivotData as $p) {
                        $placements[$p->course_id][] = [
                            'curriculum_id' => (int) $p->curriculum_id,
                            'year_level' => (string) $p->year_level,
                            'semester' => $p->semester == 1 ? '1st' : ($p->semester == 2 ? '2nd' : 'summer'),
                        ];
                    }

                    $courses->transform(function ($course) use ($placements) {
                        $rows = $placements[$course->id] ?? [];
                        $course->setAttribute('curriculum_placements', $rows);

                        if ($rows !== []) {
                            $course->year_level = $rows[0]['year_level'];
                            $course->semester = $rows[0]['semester'];
                        }

                        return $course;
                    });

                    $courses = $courses->sort(function ($a, $b) {
                        $yA = (int) ($a->year_level ?? 0);
                        $yB = (int) ($b->year_level ?? 0);
                        if ($yA !== $yB) {
                            return $yA <=> $yB;
                        }

                        $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
                        $sA = $semOrder[$a->semester ?? ''] ?? 99;
                        $sB = $semOrder[$b->semester ?? ''] ?? 99;
                        if ($sA !== $sB) {
                            return $sA <=> $sB;
                        }

                        $catA = strtolower($a->course_category ?? '') === 'major' ? 1 : 2;
                        $catB = strtolower($b->course_category ?? '') === 'major' ? 1 : 2;
                        if ($catA !== $catB) {
                            return $catA <=> $catB;
                        }

                        return strcmp($a->course_code ?? '', $b->course_code ?? '');
                    })->values();

                    return $courses;
                } else {
                    return collect();
                }
            }

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
                if ($yA !== $yB) {
                    return $yA <=> $yB;
                }

                $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
                $sA = $semOrder[$a->semester ?? ''] ?? 99;
                $sB = $semOrder[$b->semester ?? ''] ?? 99;
                if ($sA !== $sB) {
                    return $sA <=> $sB;
                }

                $catA = strtolower($a->course_category ?? '') === 'major' ? 1 : 2;
                $catB = strtolower($b->course_category ?? '') === 'major' ? 1 : 2;
                if ($catA !== $catB) {
                    return $catA <=> $catB;
                }

                return strcmp($a->course_code ?? '', $b->course_code ?? '');
            })->values();

            return $courses;
        }));
    }

    public function store(StoreCourseRequest $request)
    {
        $validated = $request->validated();

        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $validated['department_id'])) {
            return response()->json(['message' => 'You can only manage courses for your department.'], 403);
        }

        $validated = $this->clearProgramForNonMajor($validated, $validated['course_category'] ?? null);

        $course = Course::create($validated);
        ApiCache::forgetGroups(['courses.index', 'initial.data']);

        return response()->json($course->load(['department', 'program']), 201);
    }

    public function show(Request $request, Course $course)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $course->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($course->load(['department', 'program']));
    }

    public function update(UpdateCourseRequest $request, Course $course)
    {
        $validated = $request->validated();
        $validated = $this->clearProgramForNonMajor(
            $validated,
            $validated['course_category'] ?? $course->course_category,
        );

        $original = $course->getAttributes();
        $course->update($validated);
        $this->revisionChanges->recordCourseChanged($original, $course, $request->user()?->id);
        ApiCache::forgetGroups(['courses.index', 'initial.data']);

        return response()->json($course->load(['department', 'program']));
    }

    /**
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

    public function destroy(Request $request, Course $course)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $course->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (Schedule::where('course_id', $course->id)->exists()) {
            return response()->json([
                'message' => 'This course cannot be archived while classes of it are scheduled.',
            ], 422);
        }
        $course->delete();
        ApiCache::forgetGroups(['courses.index', 'initial.data']);

        return response()->json(['message' => 'Course archived successfully']);
    }
}
