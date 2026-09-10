<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\DepartmentResourceSlotLimitService;
use App\Services\Scheduling\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class InitialDataController extends Controller
{
    // Matches every other lookup group. The payload is explicitly invalidated by
    // ApiCache::forgetGroups('initial.data') on each mutation, so a short TTL
    // bought no extra freshness — it only churned ~1.7MB cache entries per miss.
    private const CACHE_TTL_SECONDS = ApiCache::LOOKUP_TTL_SECONDS;

    /**
     * The optional collections a caller may ask for by name. Everything outside
     * this list (the active term, the grid window, the readiness flags) is a
     * handful of scalars and is always returned.
     */
    private const OPTIONAL_SECTIONS = [
        'rooms',
        'courses',
        'faculties',
        'sections',
        'schedules',
        'schedule_submissions',
        'departments',
        'users',
    ];

    public function __construct(
        private readonly FacultyLoadService $facultyLoad,
        private readonly DepartmentResourceSlotLimitService $resourceLimits,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $include = $this->requestedSections($request);
        $cacheKey = ApiCache::key('initial.data', [
            'include' => $include,
            'role' => (string) ($user?->role ?? ''),
            'department_id' => $user?->isVpaa() || $user?->department_id === null
                ? null
                : (int) $user?->department_id,
            'program_id' => $user?->role === 'program_head' ? (int) ($user?->program_id ?? 0) : null,
            'per_page' => min(max((int) $request->query('per_page', 0), 0), 500),
            'schedule_limit' => min(max((int) $request->query('schedule_limit', 500), 1), 2000),
            'pages' => collect(['rooms', 'courses', 'sections', 'schedules', 'departments', 'users'])
                ->mapWithKeys(fn (string $key): array => [$key => (int) $request->query($key.'_page', 1)])
                ->all(),
        ]);

        // Cache the ENCODED payload, not the Eloquent collections that produce it.
        // Storing models meant a cache hit still paid the full serialization cost:
        // json_encode() walks every model's toArray(), casts and $appends, which
        // measured ~433ms for the largest payload (the SQL it replaced was ~46ms).
        // Caching the finished string turns a hit into a file read (~1ms).
        $json = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): string => json_encode(
                $this->buildPayload($request),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );

        return JsonResponse::fromJsonString($json);
    }

    private function buildPayload(Request $request): array
    {
        // Null means "everything", matching the pre-`include` contract.
        $include = $this->requestedSections($request);
        $wants = static fn (string $section): bool => $include === null
            || in_array($section, $include, true);

        $pageSize = min(max((int) $request->query('per_page', 0), 0), 500);
        $user = $request->user();
        $departmentId = $user->isVpaa() || $user->department_id === null
            ? null
            : (int) $user->department_id;
        $viewerDepartmentId = $departmentId;
        $facultyDepartmentId = $user->role === 'program_head' ? $departmentId : null;
        $facultyProgramId = $user->role === 'program_head' ? (int) ($user->program_id ?? 0) : null;
        $activeTerm = Cache::remember(
            ApiCache::key('terms.active'),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => Terms::query()->where('is_active', true)->first(),
        );
        $activeTermId = $activeTerm?->id;
        $rooms = ! $wants('rooms') ? collect() : Rooms::query()
            ->with('department')
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope
                    ->whereNull('department_id')
                    ->orWhere('department_id', $departmentId),
            ))
            ->get();

        $activeCurriculumQuery = Curriculum::query()->where('status', 'active');
        if ($departmentId !== null) {
            $activeCurriculumQuery->where('department_id', $departmentId);
        }
        $activeCurriculumList = $activeCurriculumQuery->get();

        $courseRelations = ['department', 'teachingDepartment', 'teachingProgram', 'program'];

        if ($wants('courses') && $activeCurriculumList->isNotEmpty()) {
            $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
            $activeSemester = match ($activeTerm?->semester) {
                '1st' => 1,
                '2nd' => 2,
                'summer' => 3,
                default => null,
            };
            $pivotData = \DB::table('curriculum_course')
                ->whereIn('curriculum_id', $activeCurriculumList->pluck('id'))
                ->when($activeSemester !== null, fn ($query) => $query->where('semester', $activeSemester))
                ->get();
            $activeSemesterCourseIds = $pivotData->pluck('course_id')->map('intval')->unique()->values();
            $configuredFieldCodes = $departmentId === null
                ? []
                : \DB::table('field_course_settings')
                    ->where('department_id', $departmentId)
                    ->whereNotNull('course_code')
                    ->pluck('course_code')
                    ->map(static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

            $courses = Course::with($courseRelations)
                ->whereIn('courses.id', $activeSemesterCourseIds)
                ->where(function ($outer) use ($activeCurriculumList, $departmentId, $configuredFieldCodes) {
                    $outer->where(function ($own) use ($activeCurriculumList, $departmentId) {
                        $own->whereHas('curriculum', function ($q) use ($activeCurriculumList) {
                            $q->whereIn('curriculum.id', $activeCurriculumList->pluck('id'));
                        })
                            // Keep configured field courses in scope even when their
                            // owning department differs; the field setting explicitly
                            // delegates them to this department's scheduler.
                            ->when($departmentId !== null, function ($q) use ($departmentId) {
                                $q->where(function ($scope) use ($departmentId) {
                                    $scope->whereNull('department_id')
                                        ->orWhere('department_id', $departmentId);
                                });
                            });
                    });

                    if ($configuredFieldCodes !== []) {
                        $outer->orWhere(function ($field) use ($activeCurriculumList, $configuredFieldCodes) {
                            $field->whereHas('curriculum', function ($q) use ($activeCurriculumList) {
                                $q->whereIn('curriculum.id', $activeCurriculumList->pluck('id'));
                            })->whereIn('course_code', $configuredFieldCodes);
                        });
                    }

                })
                ->when($facultyProgramId !== null, fn (Builder $query) => $query->where(
                    fn (Builder $programScope) => $programScope
                        ->where('program_id', $facultyProgramId)
                        ->orWhere('teaching_program_id', $facultyProgramId),
                ))
                ->get();

            $pivotMap = [];
            foreach ($pivotData as $p) {
                if (! isset($pivotMap[$p->course_id])) {
                    $pivotMap[$p->course_id] = $p;
                }
            }

            $courses = $courses->map(function ($c) use ($pivotMap) {
                if (isset($pivotMap[$c->id])) {
                    $p = $pivotMap[$c->id];
                    $c->year_level = (string) $p->year_level;
                    $c->semester = (string) $p->semester === '1' ? '1st' : ((string) $p->semester === '2' ? '2nd' : 'summer');
                }

                return $c;
            })->sort(function ($a, $b) use ($semOrder) {
                $yA = (int) ($a->year_level ?? 0);
                $yB = (int) ($b->year_level ?? 0);
                if ($yA !== $yB) {
                    return $yA <=> $yB;
                }

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
        } else {
            // No active curriculum exists for this department scope.
            // Return an empty list — courses are only meaningful in the context of an
            // active curriculum. Shared minors (null dept) are not included here either,
            // because without a curriculum they have no term/year-level placement.
            //
            $courses = $departmentId === null
                ? collect()
                : collect();
        }

        $sections = ! $wants('sections') ? collect() : Sections::query()
            // The curriculum comes along so the generator can show which one a
            // year level follows without a second round trip.
            ->with(['department', 'program', 'term', 'curriculum'])
            // A Department without a Program is not a schedulable academic scope.
            // Keep legacy schedule rows readable below, but do not offer these
            // sections to the active scheduler or generation workflows.
            ->whereHas('program')
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeTermId !== null, fn (Builder $query) => $query->where(function (Builder $q) use ($activeTermId, $activeTerm) {
                $q->where('term_id', $activeTermId)
                    ->orWhereNull('term_id');
                if ($activeTerm && ! empty($activeTerm->semester)) {
                    $q->orWhere('semester', $activeTerm->semester);
                }
            }))
            ->get();

        // Every relation below is duplicated onto each of the (up to 2,000) schedule
        // rows, while the same records already ship normalised at the top level of
        // this payload. Select only the columns the client actually reads off a
        // nested schedule relation; the full records stay available in the
        // top-level `rooms`/`courses`/`sections`/`departments` collections.
        // Unbounded columns here (departments.logo, faculties.profile_picture) would
        // otherwise be repeated once per meeting row.
        $schedules = ! $wants('schedules') ? collect() : Schedule::query()
            ->with(array_filter([
                'term:id,academic_year,semester',
                'section:id,section_name,year_level,semester,department_id,program_id,term_id',
                // teaching_department_id drives the delegated-assignment masking below.
                'course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category,room_type_required,year_level,semester,department_id,teaching_department_id,teaching_program_id,program_id',
                'faculty:id,first_name,last_name,middle_name,department_id,program_id',
                'room:id,room_code,building,room_type,allow_lecture_usage,department_id',
                'department:id,department_name,department_code',
            ]))
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                // The Schedule Builder is scoped to this department's own offerings.
                // Delegated courses and source-department rows belong to the
                // dedicated Cross-Department assignment workflow instead.
                fn (Builder $scope) => $scope
                    ->where('department_id', $departmentId),
            ))
            ->when($facultyProgramId !== null, fn (Builder $query) => $query->whereHas(
                'course',
                fn (Builder $course) => $course
                    ->where('program_id', $facultyProgramId)
                    ->orWhere('teaching_program_id', $facultyProgramId),
            ))
            ->when($activeTermId !== null, fn (Builder $query) => $query->where('term_id', $activeTermId))
            ->latest()
            // Keep the default response bounded for institution-wide viewers.
            // Callers that genuinely need more rows can opt in up to 2,000 and
            // should use the paged response mode for larger datasets.
            ->limit(min(max((int) $request->query('schedule_limit', 500), 1), 2000))
            ->get();

        $needsSubmissions = $wants('schedules') || $wants('schedule_submissions');
        $scheduleSubmissions = ! $needsSubmissions ? collect() : ScheduleSubmission::query()
            ->with([
                'sections:id,section_name,year_level,department_id,term_id',
                'submitter:id,name',
                'deanReviewer:id,name',
                'vpaaReviewer:id,name',
                'withdrawer:id,name',
            ])
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeTermId !== null, fn (Builder $query) => $query->where('term_id', $activeTermId))
            ->orderByDesc('revision_number')
            ->get();
        $latestSubmissionBySection = collect();
        foreach ($scheduleSubmissions as $submission) {
            foreach ($submission->sections as $submissionSection) {
                $sectionId = (int) $submissionSection->id;
                if (! $latestSubmissionBySection->has($sectionId)) {
                    $latestSubmissionBySection->put($sectionId, $submission);
                }
            }
        }

        // Keep the existing schedule response contract while approval ownership
        // lives in schedule_submissions. These are virtual response attributes,
        // not duplicated database columns on each timetable meeting.
        $schedules->each(function (Schedule $schedule) use ($latestSubmissionBySection): void {
            $submission = $latestSubmissionBySection->get((int) $schedule->section_id);
            if ($submission === null) {
                return;
            }
            $schedule->setAttribute('schedule_submission_id', $submission->id);
            $schedule->setAttribute('submission_status', $submission->status);
            $schedule->setAttribute('submission_revision_number', $submission->revision_number);
            $schedule->setAttribute('submitted_by', $submission->submitted_by);
            $schedule->setAttribute('submitted_at', $submission->submitted_at);
            $schedule->setAttribute('reviewed_by_dean', $submission->dean_reviewed_by);
            $schedule->setAttribute('reviewed_at_dean', $submission->dean_reviewed_at);
            $schedule->setAttribute('approved_by_vpaa', $submission->vpaa_reviewed_by);
            $schedule->setAttribute('approved_at_vpaa', $submission->vpaa_reviewed_at);
            $schedule->setAttribute('rejection_reason', $submission->rejection_reason);
            $schedule->setAttribute('approval_override', $submission->approval_override);
            $schedule->setAttribute('approval_override_reason', $submission->approval_override_reason);
        });

        // A source department must not see delegated instructor assignments until
        // the receiving department explicitly marks its assignment batch done.
        $schedules->each(function ($schedule) use ($viewerDepartmentId): void {
            $course = $schedule->course;
            if ($course?->teaching_department_id !== null
                && (int) $course->teaching_department_id !== (int) $schedule->department_id
                // Only the source department's view is masked. The receiving
                // department must continue to see the instructor it assigned,
                // even before it marks the batch complete.
                && $viewerDepartmentId !== null
                && (int) $viewerDepartmentId === (int) $schedule->department_id
                && ! (bool) $schedule->faculty_assignment_done) {
                $schedule->faculty_id = null;
                $schedule->setRelation('faculty', null);
            }
        });

        $departments = ! $wants('departments') ? collect() : Departments::query()
            ->withCount(['rooms', 'sections', 'faculties', 'programs'])
            ->with(['users' => fn ($query) => $query
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id')])
            ->latest()
            ->get();

        $payload = [
            'active_term' => $activeTerm,
            // The grid window is a stored setting (schedule_settings, PATCH
            // /timeslots/settings). The client used to hardcode 07:00-19:00 in ~40
            // places, so changing it desynchronised the whole builder (audit #33).
            'time_grid' => [
                'opening_time' => substr(SchedulingPolicy::openingTime(), 0, 5),
                'closing_time' => substr(SchedulingPolicy::closingTime(), 0, 5),
                'slot_minutes' => SchedulingPolicy::SLOT_MINUTES,
                'slot_count' => SchedulingPolicy::totalSlots(),
            ],
            'rooms' => $rooms,
            'courses' => $courses,
            // Department-wide schedulers may use the external-instructor tab. A
            // Program Head, however, owns one program roster and must never see
            // another program's instructors in Auto-Assign.
            'faculties' => $wants('faculties')
                ? $this->facultyLoad->get($facultyDepartmentId, $activeTermId, $facultyProgramId)
                : collect(),
            'sections' => $sections,
            'schedules' => $schedules,
            'schedule_submissions' => $scheduleSubmissions,
            'departments' => $departments,
            'scheduling_ready' => $departmentId === null || Departments::query()->whereKey($departmentId)->whereHas('programs')->exists(),
            // Submitting hands the schedules to a Dean, so the scheduler can
            // block the action up front instead of letting the request fail.
            'has_dean' => $departmentId === null || User::query()
                ->where('role', 'dean')
                ->where('department_id', $departmentId)
                ->where('is_active', true)
                ->exists(),
            'field_course_assignment_enabled' => SchedulingPolicy::fieldCourseSettingEnabled($departmentId),
            'field_course_codes' => array_keys(SchedulingPolicy::fieldCourseCodeMap($departmentId)),
            'resource_slot_limits' => $departmentId !== null
                ? $this->resourceLimits->forDepartment($departmentId)
                : null,
            // Only the signatory lookup in the teaching-load export reads this, and
            // it needs four columns. Returning full models shipped every column of
            // every user on every scheduler load.
            //
            // The VPAA is a college-wide signatory with no department of their own,
            // so a department-scoped list would omit the very account the load
            // sheet's "Recommending Approval" line is stamped from.
            'users' => ! $wants('users') ? collect() : User::query()
                ->when($departmentId !== null, fn (Builder $query) => $query->where(
                    fn (Builder $scope) => $scope
                        ->where('department_id', $departmentId)
                        ->orWhere('role', 'vpaa'),
                ))
                ->latest()
                ->get(['id', 'name', 'role', 'department_id']),
        ];

        // Opt-in pagination keeps existing clients backward compatible while
        // allowing large deployments to load the heaviest collections in pages.
        if ($pageSize > 0) {
            foreach (['rooms', 'courses', 'sections', 'schedules', 'departments', 'users'] as $key) {
                $items = collect($payload[$key] ?? []);
                $payload[$key] = [
                    'data' => $items->forPage(max(1, (int) $request->query($key.'_page', 1)), $pageSize)->values(),
                    'meta' => [
                        'page' => max(1, (int) $request->query($key.'_page', 1)),
                        'per_page' => $pageSize,
                        'total' => $items->count(),
                        'last_page' => max(1, (int) ceil($items->count() / $pageSize)),
                    ],
                ];
            }
        }

        if ($include !== null) {
            $payload = array_filter(
                $payload,
                static fn (string $key): bool => ! in_array($key, self::OPTIONAL_SECTIONS, true)
                    || in_array($key, $include, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $payload;
    }

    /**
     * The collections this request asked for, or null for the full payload.
     *
     * `?include=rooms,departments,schedules` lets a page that renders one table
     * skip the rest of the system. Unknown names are ignored rather than
     * rejected, and an include listing nothing valid falls back to everything —
     * a typo degrades to the old behaviour instead of returning a blank page.
     */
    private function requestedSections(Request $request): ?array
    {
        $raw = $request->query('include');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $requested = collect(explode(',', $raw))
            ->map(static fn (string $name): string => strtolower(trim($name)))
            ->filter(static fn (string $name): bool => in_array($name, self::OPTIONAL_SECTIONS, true))
            ->unique()
            // Two requests for the same sections in a different order must hit
            // the same cache entry.
            ->sort()
            ->values()
            ->all();

        return $requested === [] ? null : $requested;
    }

}
