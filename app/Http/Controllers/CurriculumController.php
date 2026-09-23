<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Curriculum;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class CurriculumController extends Controller
{
    public function __construct(private readonly ScheduleAuthorizationService $authorization) {}

    public function index(Request $request)
    {
        $query = Curriculum::with(['department', 'program'])
            ->withCount('courses')
            // How many cohorts still follow this curriculum. Drives the "in use"
            // badge; informational only, since an assignment on its own is
            // undone with a dropdown.
            ->withCount(['sections as active_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')])
            // The subset of those that have a timetable plotted from it. This
            // is what the retirement guard keys on.
            ->withCount(['scheduledSections as scheduled_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')]);

        if ($this->authorization->rejectsRequestedDepartment($request, $request->query('department_id'))) {
            return response()->json(['message' => 'You can only view curriculum for your department.'], 403);
        }

        $departmentId = $this->authorization->requestedDepartment($request, $request->query('department_id'));
        $status = $request->has('status') && $request->status !== 'all'
            ? (string) $request->status
            : null;

        // Nine mutation paths already call ApiCache::forgetGroups(['curriculum.index'])
        // but nothing ever read that group, so the bumps were inert. Reading it here
        // makes the existing invalidation meaningful.
        $curriculumList = Cache::remember(
            ApiCache::key('curriculum.index', [
                'department_id' => $departmentId,
                'status' => $status,
            ]),
            ApiCache::LOOKUP_TTL_SECONDS,
            function () use ($query, $departmentId, $status) {
                $curricula = $query
                    ->when($departmentId !== null, fn ($scope) => $scope->where('department_id', $departmentId))
                    ->when($status !== null, fn ($scope) => $scope->where('status', $status))
                    // Newest effective year first so the list reads in the same
                    // order as the new/old badges the annotation assigns.
                    ->orderByDesc('effective_school_year')
                    ->orderBy('created_at', 'desc')
                    ->get();

                // Ranking new against old needs every active sibling in the
                // group, which a status filter would hide. Annotate against the
                // unfiltered set, then return only what was asked for.
                if ($status !== null) {
                    $siblings = Curriculum::query()
                        ->when($departmentId !== null, fn ($scope) => $scope->where('department_id', $departmentId))
                        ->get(['id', 'department_id', 'program_id', 'effective_school_year', 'status']);
                    Curriculum::annotateLifecycle($siblings);
                    $labels = $siblings->keyBy('id');

                    foreach ($curricula as $curriculum) {
                        $match = $labels->get($curriculum->id);
                        $curriculum->setAttribute('lifecycle', $match?->lifecycle);
                        $curriculum->setAttribute('lifecycle_label', $match?->lifecycle_label);
                    }

                    return $curricula;
                }

                return Curriculum::annotateLifecycle($curricula);
            },
        );

        return response()->json($curriculumList);
    }

    /**
     * New-vs-old is a statement about a curriculum's siblings, so a single
     * record cannot label itself. Load the group and rank within it.
     */
    private function annotateAgainstSiblings(Curriculum $curriculum): void
    {
        $siblings = Curriculum::query()
            ->where('department_id', $curriculum->department_id)
            ->get(['id', 'department_id', 'program_id', 'effective_school_year', 'status']);

        Curriculum::annotateLifecycle($siblings);
        $match = $siblings->firstWhere('id', $curriculum->id);

        $curriculum->setAttribute('lifecycle', $match?->lifecycle);
        $curriculum->setAttribute('lifecycle_label', $match?->lifecycle_label);
    }

    /**
     * Refuses to retire a curriculum that active cohorts have already been
     * scheduled from.
     *
     * Nothing else stops it: sections.curriculum_id is restrictOnDelete, but a
     * status change is not a delete, and a section pointed at a deactivated or
     * archived curriculum would fail generation with a confusing error far from
     * the action that caused it. Answer here instead, naming the cohorts.
     *
     * The bar is a plotted schedule, not a year-level assignment. A cohort that
     * is merely pointed at this curriculum has nothing to strand -- reassigning
     * it is one dropdown -- and blocking on that alone made it impossible to
     * retire a curriculum nobody had generated against.
     */
    private function rejectIfStillInUse(Curriculum $curriculum, string $targetStatus): ?\Illuminate\Http\JsonResponse
    {
        $sections = $curriculum->scheduledSections()
            ->where('sections.status', 'active')
            ->orderBy('year_level')
            ->orderBy('section_name')
            ->get(['id', 'section_name', 'year_level']);

        if ($sections->isEmpty()) {
            return null;
        }

        $verb = $targetStatus === 'archived' ? 'Archive' : 'Deactivate';
        $names = $sections->pluck('section_name')->take(5)->implode(', ');
        $overflow = $sections->count() > 5 ? sprintf(' and %d more', $sections->count() - 5) : '';

        return response()->json([
            'message' => sprintf(
                'Cannot %s this curriculum: %d active section%s already ha%s a schedule plotted from it (%s%s). Archive or clear those schedules first.',
                strtolower($verb),
                $sections->count(),
                $sections->count() === 1 ? '' : 's',
                $sections->count() === 1 ? 's' : 've',
                $names,
                $overflow,
            ),
            'blocking_sections' => $sections,
        ], 422);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $isPrivileged = in_array($user->role, ['vpaa', 'super_admin']);

        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:curriculum,code',
            'program_id' => [
                'nullable',
                'integer',
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id') ?: $user->department_id)),
            ],
            'effective_school_year' => 'required|string|max:20',
            'status' => 'nullable|string|in:active,deactivated,archived',
            'description' => 'nullable|string',
        ];

        if ($isPrivileged) {
            $rules['department_id'] = 'nullable|exists:departments,id';
        }

        $validated = $request->validate($rules);
        // A new curriculum starts out of service until somebody activates it.
        $validated['status'] = $validated['status'] ?? 'deactivated';

        if (! $isPrivileged) {
            $validated['department_id'] = $user->department_id;
        }

        // Activating a curriculum no longer demotes its siblings: a department
        // mid-transition runs the old and the new one side by side, and each
        // section says which of them it follows.
        $curriculum = Curriculum::create($validated);

        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json($curriculum, 201);
    }

    public function show(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $curriculum->loadCount('courses');
        $curriculum->loadCount(['sections as active_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')]);
        $curriculum->loadCount(['scheduledSections as scheduled_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')]);
        $curriculum->load(['department', 'program']);
        $this->annotateAgainstSiblings($curriculum);

        return response()->json($curriculum);
    }

    public function update(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $user = $request->user();
        $isPrivileged = in_array($user->role, ['vpaa', 'super_admin']);

        $rules = [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50|unique:curriculum,code,'.$curriculum->id,
            'program_id' => [
                'nullable',
                'integer',
                Rule::exists('programs', 'id')->where(fn ($query) => $query->where('department_id', $request->input('department_id') ?: $curriculum->department_id)),
            ],
            'effective_school_year' => 'sometimes|string|max:20',
            'status' => 'nullable|string|in:active,deactivated,archived',
            'description' => 'nullable|string',
        ];

        if ($isPrivileged) {
            $rules['department_id'] = 'nullable|exists:departments,id';
        }

        $validated = $request->validate($rules);

        if (! $isPrivileged) {
            unset($validated['department_id']);
        }

        $newStatus = $validated['status'] ?? $curriculum->status;
        if ($newStatus !== 'active' && $curriculum->status === 'active'
            && ($blocked = $this->rejectIfStillInUse($curriculum, $newStatus)) !== null) {
            return $blocked;
        }

        $curriculum->update($validated);

        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json($curriculum);
    }

    public function destroy(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (($blocked = $this->rejectIfStillInUse($curriculum, 'archived')) !== null) {
            return $blocked;
        }

        $curriculum->update(['status' => 'archived']);

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json(['message' => 'Curriculum archived successfully']);
    }

    public function duplicate(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $newCurriculum = Curriculum::create([
            'name' => $curriculum->name.' (Copy)',
            'code' => $curriculum->code.'-COPY-'.time(),
            'department_id' => $curriculum->department_id,
            'program_id' => $curriculum->program_id,
            'effective_school_year' => $curriculum->effective_school_year,
            'status' => 'deactivated',
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

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json($newCurriculum, 201);
    }

    public function updateStatus(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:active,deactivated,archived',
        ]);

        // An active curriculum may now be retired directly, but only once no
        // cohort still follows it. That check replaces the old blanket refusal,
        // which existed only because deactivating used to be the way to make
        // room for a different active curriculum.
        if ($validated['status'] !== 'active'
            && ($blocked = $this->rejectIfStillInUse($curriculum, $validated['status'])) !== null) {
            return $blocked;
        }

        $curriculum->update(['status' => $validated['status']]);

        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json($curriculum);
    }

    public function attachCourse(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'year_level' => 'required|integer|between:1,4',
            'semester' => 'required|integer|between:1,3',
            'replace_course_id' => 'sometimes|integer|exists:courses,id',
        ]);

        $course = Course::findOrFail($validated['course_id']);
        $this->ensureCourseBelongsToCurriculumDepartment($curriculum, $course);

        \DB::transaction(function () use ($curriculum, $validated) {
            $curriculum->courses()->syncWithoutDetaching([
                $validated['course_id'] => [
                    'year_level' => $validated['year_level'],
                    'semester' => $validated['semester'],
                ],
            ]);

            if (
                isset($validated['replace_course_id'])
                && (int) $validated['replace_course_id'] !== (int) $validated['course_id']
            ) {
                $curriculum->courses()->detach((int) $validated['replace_course_id']);
            }
        });

        Course::syncPlacementFromCurricula(array_filter([$validated['course_id'], $validated['replace_course_id'] ?? null]));
        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json(['message' => 'Course attached successfully']);
    }

    public function attachCoursesBatch(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'courses' => 'required|array|min:1',
            'courses.*.course_id' => 'required|integer|exists:courses,id',
            'courses.*.year_level' => 'required|integer|between:1,4',
            'courses.*.semester' => 'required|integer|between:1,3',
        ]);

        $syncData = [];
        foreach ($validated['courses'] as $item) {
            $course = Course::findOrFail($item['course_id']);
            $this->ensureCourseBelongsToCurriculumDepartment($curriculum, $course);

            $syncData[$item['course_id']] = [
                'year_level' => $item['year_level'],
                'semester' => $item['semester'],
            ];
        }

        $curriculum->courses()->syncWithoutDetaching($syncData);

        Course::syncPlacementFromCurricula(array_keys($syncData));
        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json(['message' => count($syncData).' course(s) attached successfully']);
    }

    public function batchCreateAndAttachCourses(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

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
            $name = trim(preg_replace('/\s+/', ' ', $item['course_name']));
            $category = $item['course_category'];
            $lec = $item['lecture_hours'];
            $lab = $item['lab_hours'];
            $units = $item['units'];
            $yearLevel = $item['year_level'];
            $semester = $item['semester'];

            try {
                \DB::beginTransaction();

                // 1. Check if course already exists (majors scoped to department, minors globally)
                $course = null;
                if ($category === 'minor') {
                    $course = Course::where('course_code', $code)
                        ->where('course_category', 'minor')
                        ->first();
                } else {
                    $course = Course::where('course_code', $code)
                        ->where('department_id', $curriculum->department_id)
                        ->first();
                }

                $semStr = $semester == 1 ? '1st' : ($semester == 2 ? '2nd' : 'summer');

                if (! $course) {
                    // Create course
                    $course = Course::create([
                        'course_code' => $code,
                        'course_name' => $name,
                        'lecture_hours' => $lec,
                        'lab_hours' => $lab,
                        'units' => $units,
                        'course_category' => $category,
                        'room_type_required' => $lab > 0 ? 'laboratory' : 'lecture',
                        'year_level' => (string) $yearLevel,
                        'semester' => $semStr,
                        'department_id' => $category === 'minor' ? null : $curriculum->department_id,
                        'status' => 'active',
                    ]);
                } else {
                    $this->ensureCourseBelongsToCurriculumDepartment($curriculum, $course);

                    $course->update([
                        'course_name' => $name,
                        'lecture_hours' => $lec,
                        'lab_hours' => $lab,
                        'units' => $units,
                        'course_category' => $category,
                        'room_type_required' => $lab > 0 ? 'laboratory' : 'lecture',
                        'year_level' => (string) $yearLevel,
                        'semester' => $semStr,
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
                            'message' => 'Course is already attached to this semester.',
                        ];
                        \DB::commit();

                        continue;
                    } else {
                        throw new \Exception('Course code is already used in another semester of this curriculum.');
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
                    'course' => $course,
                ];
            } catch (\Exception $e) {
                \DB::rollBack();
                $results[] = [
                    'row_id' => $rowId,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        Course::syncPlacementFromCurricula($curriculum->courses()->pluck('courses.id'));
        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json([
            'results' => $results,
        ]);
    }

    public function detachCourse(Request $request, Curriculum $curriculum, Course $course)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $curriculum->courses()->detach($course->id);

        Course::syncPlacementFromCurricula([$course->id]);
        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json(['message' => 'Course removed successfully']);
    }

    public function showWithCourses(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $curriculum->loadCount('courses');
        $curriculum->load('department');

        $courses = $curriculum->courses()
            ->where(function ($query) use ($curriculum) {
                $query->whereNull('courses.department_id')
                    ->orWhere('courses.department_id', $curriculum->department_id);
            })
            ->orderBy('curriculum_course.year_level')
            ->orderBy('curriculum_course.semester')
            ->get();

        $grouped = $courses->groupBy(fn ($c) => $c->pivot->year_level.'-'.$c->pivot->semester)
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'year_level' => (int) $first->pivot->year_level,
                    'semester' => (int) $first->pivot->semester,
                    'courses' => $group->sort(function ($a, $b) {
                        $catA = strtolower($a->course_category ?? '') === 'major' ? 1 : 2;
                        $catB = strtolower($b->course_category ?? '') === 'major' ? 1 : 2;
                        if ($catA !== $catB) {
                            return $catA <=> $catB;
                        }

                        return strcmp($a->course_code ?? '', $b->course_code ?? '');
                    })->map(fn ($c) => [
                        'id' => $c->id,
                        'code' => $c->course_code,
                        'title' => $c->course_name,
                        'category' => $c->course_category,
                        'lec_units' => $c->lecture_hours,
                        'lab_units' => $c->lab_hours,
                        'total_units' => $c->units,
                        // Which program owns a major decides who may teach it, so
                        // the course editor shows and edits it here.
                        'program_id' => $c->program_id,
                    ])->values(),
                    'totals' => [
                        'lec' => $group->sum('lecture_hours'),
                        'lab' => $group->sum('lab_hours'),
                        'tu' => $group->sum('units'),
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
                'effective_school_year' => $curriculum->effective_school_year,
                'status' => $curriculum->status,
                'description' => $curriculum->description,
                'courses_count' => $curriculum->courses_count,
            ],
            'semesters' => $grouped,
        ]);
    }

    private function ensureCourseBelongsToCurriculumDepartment(Curriculum $curriculum, Course $course): void
    {
        if (
            $course->department_id !== null &&
            (int) $course->department_id !== (int) $curriculum->department_id
        ) {
            abort(422, 'Course belongs to another department and cannot be attached to this curriculum.');
        }
    }
}
