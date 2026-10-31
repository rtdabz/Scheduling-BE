<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Course\BatchStoreCoursesRequest;
use App\Models\Course;
use App\Models\Curriculum;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class CurriculumController extends Controller
{
    public function __construct(private readonly ScheduleAuthorizationService $authorization) {}

    public function index(Request $request)
    {
        $query = Curriculum::with(['department', 'program'])
            ->withCount('courses')
            ->withCount(['sections as active_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')])
            ->withCount(['scheduledSections as scheduled_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')]);

        if ($this->authorization->rejectsRequestedDepartment($request, $request->query('department_id'))) {
            return response()->json(['message' => 'You can only view curriculum for your department.'], 403);
        }

        $departmentId = $this->authorization->requestedDepartment($request, $request->query('department_id'));
        $programId = $this->authorization->programScope($request);
        $this->authorization->scopeCurriculaToProgram($query, $request);
        $status = $request->has('status') && $request->status !== 'all'
            ? (string) $request->status
            : null;

        // Schedule mutations version the bootstrap data; the usage count depends on those rows.
        $curriculumList = Cache::remember(
            ApiCache::compositeKey('curriculum.index', ['departments.index', 'courses.index', 'sections.index', 'initial.data', 'initial.data.schedules'], [
                'department_id' => $departmentId,
                'program_id' => $programId,
                'status' => $status,
            ]),
            ApiCache::LOOKUP_TTL_SECONDS,
            function () use ($query, $departmentId, $status) {
                $curricula = $query
                    ->when($departmentId !== null, fn ($scope) => $scope->where('department_id', $departmentId))
                    ->when($status !== null, fn ($scope) => $scope->where('status', $status))
                    ->orderByDesc('effective_school_year')
                    ->orderBy('created_at', 'desc')
                    ->get();

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

        $rules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:curriculum,code',
            'program_id' => [
                'nullable',
                'integer',
                Rule::exists('programs', 'id')->whereNull('deleted_at')->where(fn ($query) => $query->where('department_id', $user->department_id)),
            ],
            'effective_school_year' => ['required', 'string', $this->schoolYearRule()],
            'status' => 'nullable|string|in:active,deactivated,archived',
            'description' => 'nullable|string',
            ...$this->cmoRules(),
        ];

        $validated = $request->validate($rules);
        $validated['status'] = $validated['status'] ?? 'active';

        $validated['department_id'] = $user->department_id;
        if ($user->role === 'program_head') {
            if ($user->program_id === null) {
                return response()->json(['message' => 'Your account is not linked to a program yet.'], 403);
            }
            $validated['program_id'] = $user->program_id;
        }

        $this->validateParentScope($validated);
        $curriculum = Curriculum::create($validated);

        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json($curriculum, 201);
    }

    public function show(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)
            || ! $this->authorization->curriculumBelongsToProgram($request, $curriculum->program_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $curriculum->loadCount('courses');
        $curriculum->loadCount(['sections as active_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')]);
        $curriculum->loadCount(['scheduledSections as scheduled_sections_count' => fn ($scope) => $scope->where('sections.status', 'active')]);
        $curriculum->load(['department', 'program']);
        $this->annotateAgainstSiblings($curriculum);

        return response()->json($curriculum);
    }

    private function canAuthor(Request $request, Curriculum $curriculum): bool
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)) {
            return false;
        }
        $user = $request->user();

        return $user->role !== 'program_head'
            || ($user->program_id !== null && (int) $curriculum->program_id === (int) $user->program_id);
    }

    /** @return array<string, mixed> */
    private function cmoRules(): array
    {
        return [
            'cmo_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z-]+$/'],
            'cmo_series' => 'nullable|integer|between:1900,2100',
        ];
    }

    private function schoolYearRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{4})$/', $value, $years)
                || (int) $years[2] !== (int) $years[1] + 1) {
                $fail('The effective school year must be two consecutive years, e.g. 2025-2026.');
            }
        };
    }

    public function update(Request $request, Curriculum $curriculum)
    {
        if (! $this->canAuthor($request, $curriculum)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $rules = [
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:255|unique:curriculum,code,'.$curriculum->id,
            'program_id' => [
                'nullable',
                'integer',
                Rule::exists('programs', 'id')->whereNull('deleted_at')->where(fn ($query) => $query->where('department_id', $curriculum->department_id)),
            ],
            'effective_school_year' => ['sometimes', 'string', $this->schoolYearRule()],
            'status' => 'nullable|string|in:active,deactivated,archived',
            'description' => 'nullable|string',
            ...$this->cmoRules(),
        ];

        $validated = $request->validate($rules);
        if ($request->user()->role === 'program_head') {
            unset($validated['program_id']);
        }

        $newStatus = $validated['status'] ?? $curriculum->status;
        if ($newStatus !== 'active' && $curriculum->status === 'active'
            && ($blocked = $this->rejectIfStillInUse($curriculum, $newStatus)) !== null) {
            return $blocked;
        }

        $this->validateParentScope(array_replace($curriculum->getAttributes(), $validated));
        $curriculum->update($validated);

        $curriculum->loadCount('courses');

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json($curriculum);
    }

    public function destroy(Request $request, Curriculum $curriculum)
    {
        if (! $this->canAuthor($request, $curriculum)) {
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
        if (! $this->canAuthor($request, $curriculum)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $newCurriculum = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $curriculum) {
            $curriculum = Curriculum::query()->lockForUpdate()->findOrFail($curriculum->id);
            abort_unless($this->canAuthor($request, $curriculum), 403);
            $this->validateParentScope($curriculum->getAttributes());
            $newCurriculum = Curriculum::create([
                'name' => $curriculum->name.' (Copy)',
                'code' => $curriculum->code.'-COPY-'.time(),
                'cmo_number' => $curriculum->cmo_number,
                'cmo_series' => $curriculum->cmo_series,
                'department_id' => $curriculum->department_id,
                'program_id' => $curriculum->program_id,
                'effective_school_year' => $curriculum->effective_school_year,
                'status' => 'deactivated',
                'description' => $curriculum->description,
            ]);

            $courses = $curriculum->courses()->get();
            if ($courses->isNotEmpty()) {
                $attachData = [];
                // Frozen placement snapshots travel with the copy; it is never re-converted with current rates.
                foreach ($courses as $course) {
                    $attachData[$course->id] = collect(Curriculum::PLACEMENT_COLUMNS)
                        ->mapWithKeys(fn (string $column): array => [$column => $course->pivot->{$column}])
                        ->all();
                }
                $newCurriculum->courses()->attach($attachData);
            }

            $newCurriculum->loadCount('courses');

            return $newCurriculum;
        });

        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json($newCurriculum, 201);
    }

    public function updateStatus(Request $request, Curriculum $curriculum)
    {
        if (! $this->canAuthor($request, $curriculum)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:active,deactivated,archived',
        ]);

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
        if (! $this->canAuthor($request, $curriculum)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id,deleted_at,NULL',
            'year_level' => 'required|integer|between:1,4',
            'semester' => 'required|integer|between:1,3',
            'replace_course_id' => 'sometimes|integer|exists:courses,id,deleted_at,NULL',
        ]);

        $course = Course::findOrFail($validated['course_id']);
        $this->ensureCourseBelongsToCurriculumDepartment($curriculum, $course);

        \DB::transaction(function () use ($curriculum, $validated) {
            Curriculum::whereKey($curriculum->id)->lockForUpdate()->firstOrFail();
            $this->guardCoursePlacement($curriculum, (int) $validated['course_id'], $validated);
            if (isset($validated['replace_course_id']) && (int) $validated['replace_course_id'] !== (int) $validated['course_id']) {
                $this->guardCoursePlacement($curriculum, (int) $validated['replace_course_id']);
            }
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
        if (! $this->canAuthor($request, $curriculum)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'courses' => 'required|array|min:1',
            'courses.*.course_id' => 'required|integer|exists:courses,id,deleted_at,NULL',
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

        \DB::transaction(function () use ($curriculum, $syncData): void {
            Curriculum::whereKey($curriculum->id)->lockForUpdate()->firstOrFail();
            foreach ($syncData as $courseId => $placement) {
                $this->guardCoursePlacement($curriculum, (int) $courseId, $placement);
            }
            $curriculum->courses()->syncWithoutDetaching($syncData);
        });

        Course::syncPlacementFromCurricula(array_keys($syncData));
        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json(['message' => count($syncData).' course(s) attached successfully']);
    }

    public function batchCreateAndAttachCourses(BatchStoreCoursesRequest $request, Curriculum $curriculum)
    {
        if (! $this->canAuthor($request, $curriculum)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        validator(['department_id' => $curriculum->department_id], [
            'department_id' => ['required', Rule::exists('departments', 'id')->whereNull('deleted_at')],
        ])->validate();

        $validated = $request->validated();

        $results = [];

        foreach ($validated['courses'] as $item) {
            $rowId = $item['row_id'];
            $code = $item['course_code'];
            $name = trim(preg_replace('/\s+/', ' ', $item['course_name']));
            $category = $item['course_category'];
            $lec = $item['lecture_hours'];
            $lab = $item['lab_hours'];
            $units = $item['units'];
            $yearLevel = $item['year_level'];
            $semester = $item['semester'];

            try {
                \DB::beginTransaction();

                $course = Course::withTrashed()->where('course_code', $code)
                    ->where('department_id', $curriculum->department_id)
                    ->first();

                if ($course?->trashed()) {
                    \DB::rollBack();
                    $results[] = [
                        'row_id' => $rowId,
                        'status' => 'error',
                        'message' => $request->messages()['course_code.unique'],
                    ];

                    continue;
                }

                $semStr = $semester == 1 ? '1st' : ($semester == 2 ? '2nd' : 'summer');

                if (! $course) {
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
                        'department_id' => $curriculum->department_id,
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

                $isAttached = $curriculum->courses()->where('courses.id', $course->id)->exists();

                if ($isAttached) {
                    $sameAttached = $curriculum->courses()
                        ->where('courses.id', $course->id)
                        ->wherePivot('year_level', $yearLevel)
                        ->wherePivot('semester', $semester)
                        ->exists();

                    if ($sameAttached) {
                        \DB::commit();
                        $results[] = [
                            'row_id' => $rowId,
                            'status' => 'success',
                            'course' => $course,
                            'message' => 'Course is already attached to this semester.',
                        ];
                        continue;
                    } else {
                        \DB::rollBack();
                        $results[] = [
                            'row_id' => $rowId,
                            'status' => 'error',
                            'message' => 'Course code is already used in another semester of this curriculum.',
                        ];

                        continue;
                    }
                }

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
            } catch (\Throwable $e) {
                \DB::rollBack();
                report($e);
                $results[] = [
                    'row_id' => $rowId,
                    'status' => 'error',
                    'message' => 'Unable to save this course. Please retry this row. If the problem continues, contact your administrator.',
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
        if (! $this->canAuthor($request, $curriculum)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($blocked = $this->scheduledCourseRemovalResponse($curriculum, $course)) {
            return $blocked;
        }

        $curriculum->courses()->detach($course->id);

        Course::syncPlacementFromCurricula([$course->id]);
        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return response()->json(['message' => 'Course removed successfully']);
    }

    public function showWithCourses(Request $request, Curriculum $curriculum)
    {
        if (! $this->authorization->payloadBelongsToDepartment($request, (int) $curriculum->department_id)
            || ! $this->authorization->curriculumBelongsToProgram($request, $curriculum->program_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $curriculum->loadCount('courses');
        $curriculum->load('department');

        $courses = $curriculum->courses()
            ->where(function ($query) use ($curriculum) {
                $query->where('courses.department_id', $curriculum->department_id);
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
                        'program_id' => $c->program_id,
                        'department_id' => $c->department_id,
                        'prerequisites_text' => $c->pivot->prerequisites_text,
                        // Null for placements written before imports: those keep the legacy credit-derived durations.
                        'placement' => $c->pivot->conversion_snapshot === null ? null : [
                            'lecture_units' => $c->pivot->lecture_units,
                            'lab_units' => $c->pivot->lab_units,
                            'lecture_minutes' => $c->pivot->lecture_minutes,
                            'laboratory_minutes' => $c->pivot->laboratory_minutes,
                            'conversion_snapshot' => json_decode((string) $c->pivot->conversion_snapshot, true),
                        ],
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
                'cmo_number' => $curriculum->cmo_number,
                'cmo_series' => $curriculum->cmo_series,
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

    private function validateParentScope(array $values): void
    {
        validator($values, [
            'department_id' => ['nullable', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'program_id' => ['nullable', Rule::exists('programs', 'id')->whereNull('deleted_at')->where('department_id', $values['department_id'])],
        ])->validate();
    }

    private function guardCoursePlacement(Curriculum $curriculum, int $courseId, ?array $placement = null): void
    {
        $existing = $curriculum->courses()->where('courses.id', $courseId)->first();
        if ($existing === null) {
            return;
        }
        if ($placement !== null && (int) $existing->pivot->year_level === (int) $placement['year_level'] && (int) $existing->pivot->semester === (int) $placement['semester']) {
            return;
        }
        if ($blocked = $this->scheduledCourseRemovalResponse($curriculum, $existing)) {
            throw new HttpResponseException($blocked);
        }
    }

    private function scheduledCourseRemovalResponse(Curriculum $curriculum, Course $course): ?\Illuminate\Http\JsonResponse
    {
        $sections = $curriculum->sections()
            ->where('sections.status', 'active')
            ->whereHas('schedules', function ($schedules) use ($course): void {
                $schedules->where('schedules.course_id', $course->id)
                    ->where(function ($scope): void {
                        $scope->whereColumn('schedules.curriculum_id', 'sections.curriculum_id')
                            ->orWhereNull('schedules.curriculum_id');
                    });
            })
            ->orderBy('year_level')
            ->orderBy('section_name')
            ->get(['id', 'section_name', 'year_level']);

        if ($sections->isNotEmpty()) {
            $overflow = $sections->count() > 5 ? sprintf(' and %d more', $sections->count() - 5) : '';

            return response()->json([
                'message' => sprintf(
                    'Cannot remove %s: %d active section%s already ha%s it scheduled (%s%s). Clear those classes first.',
                    $course->course_code,
                    $sections->count(),
                    $sections->count() === 1 ? '' : 's',
                    $sections->count() === 1 ? 's' : 've',
                    $sections->pluck('section_name')->take(5)->implode(', '),
                    $overflow,
                ),
                'blocking_sections' => $sections,
            ], 422);
        }

        return null;
    }

    private function ensureCourseBelongsToCurriculumDepartment(Curriculum $curriculum, Course $course): void
    {
        if ((int) $course->department_id !== (int) $curriculum->department_id) {
            abort(422, 'Course belongs to another department and cannot be attached to this curriculum.');
        }
    }
}
