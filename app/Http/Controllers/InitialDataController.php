<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class InitialDataController extends Controller
{
    // Matches every other lookup group. The payload is explicitly invalidated by
    // ApiCache::forgetGroups('initial.data') on each mutation, so a short TTL
    // bought no extra freshness — it only churned ~1.7MB cache entries per miss.
    private const CACHE_TTL_SECONDS = ApiCache::LOOKUP_TTL_SECONDS;

    /**
     * The optional collections a caller may ask for by name. Everything outside
     * this list (the active semester, the grid window, the readiness flags) is a
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
        private readonly ScheduleAuthorizationService $authorization,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $include = $this->requestedSections($request);
        // Versioned per requested section as well as by the group as a whole, so
        // a write that only touches one collection (a new account changes
        // `users`/`faculties`) no longer discards the cached payload of a page
        // that asked for none of it (`?include=rooms,departments,schedules`).
        // Writes that cannot be narrowed still bump `initial.data` itself.
        $sectionGroups = array_map(
            static fn (string $section): string => 'initial.data.'.$section,
            $include ?? self::OPTIONAL_SECTIONS,
        );
        $cacheKey = ApiCache::compositeKey('initial.data', $sectionGroups, [
            'include' => $include,
            'role' => (string) ($user?->role ?? ''),
            'department_id' => $user?->isVpaa() || $user?->department_id === null
                ? null
                : (int) $user?->department_id,
            'program_id' => $user?->role === 'program_head' ? (int) ($user?->program_id ?? 0) : null,
            // A VPAA's payload carries approved meetings only; the approval
            // queue asks for the pending ones too, so the two must not share a
            // cached payload.
            'approval_queue' => $this->authorization->visibleScheduleStatuses($request),
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

    /**
     * A course this department offers through a curriculum of its own: one
     * placed in an active curriculum and either owned by the department or a
     * shared minor, plus any course its field-course setting has delegated to
     * it regardless of owner.
     *
     * @param  Collection<int, Curriculum>  $activeCurriculumList
     * @param  list<string>  $configuredFieldCodes
     */
    private function curricularCourseScope(
        Collection $activeCurriculumList,
        ?int $departmentId,
        array $configuredFieldCodes,
    ): Closure {
        return function ($outer) use ($activeCurriculumList, $departmentId, $configuredFieldCodes): void {
            $outer->where(function ($own) use ($activeCurriculumList, $departmentId) {
                $own->whereHas('curriculum', function ($q) use ($activeCurriculumList) {
                    $q->whereIn('curriculum.id', $activeCurriculumList->pluck('id'));
                })
                    ->when($departmentId !== null, function ($q) use ($departmentId) {
                        $q->where(function ($scope) use ($departmentId) {
                            $scope->whereNull('department_id')
                                ->orWhere('department_id', $departmentId);
                        });
                    });
            });

            // Keep configured field courses in scope even when their owning
            // department differs; the field setting explicitly delegates them to
            // this department's scheduler.
            if ($configuredFieldCodes !== []) {
                $outer->orWhere(function ($field) use ($activeCurriculumList, $configuredFieldCodes) {
                    $field->whereHas('curriculum', function ($q) use ($activeCurriculumList) {
                        $q->whereIn('curriculum.id', $activeCurriculumList->pluck('id'));
                    })->whereIn('course_code', $configuredFieldCodes);
                });
            }
        };
    }

    private function buildPayload(Request $request): array
    {
        // Null means "everything", matching the pre-`include` contract.
        $include = $this->requestedSections($request);
        $wants = static fn (string $section): bool => $include === null
            || in_array($section, $include, true);

        $pageSize = min(max((int) $request->query('per_page', 0), 0), 500);
        $scheduleLimit = min(max((int) $request->query('schedule_limit', 500), 1), 2000);
        $user = $request->user();
        $departmentId = $user->isVpaa() || $user->department_id === null
            ? null
            : (int) $user->department_id;
        $viewerDepartmentId = $departmentId;
        $facultyDepartmentId = $user->role === 'program_head' ? $departmentId : null;
        $facultyProgramId = $user->role === 'program_head' ? (int) ($user->program_id ?? 0) : null;
        $activeSemester = Cache::remember(
            ApiCache::key('semesters.active'),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => Semester::query()->where('is_active', true)->first(),
        );
        $activeSemesterId = $activeSemester?->id;
        // Rooms another department lent this one for the active semester ride along
        // with their windows, so the builder can offer them and say when.
        $grantWindows = $departmentId !== null && $activeSemesterId !== null && $wants('rooms')
            ? app(RoomAccessPolicy::class)->grantWindowsFor((int) $departmentId, (int) $activeSemesterId)
            : [];
        $rooms = ! $wants('rooms') ? collect() : Rooms::query()
            ->with('department')
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope
                    ->whereNull('department_id')
                    ->orWhere('department_id', $departmentId)
                    ->when($grantWindows !== [], fn (Builder $granted) => $granted->orWhereIn('id', array_keys($grantWindows))),
            ))
            ->get()
            ->each(function (Rooms $room) use ($grantWindows): void {
                if (isset($grantWindows[(int) $room->id])) {
                    $room->setAttribute('grant_windows', array_map(
                        static fn (array $window): array => [
                            'day' => $window['day'],
                            'start_time' => substr($window['start_time'], 0, 5),
                            'end_time' => substr($window['end_time'], 0, 5),
                        ],
                        $grantWindows[(int) $room->id],
                    ));
                }
            });

        $activeCurriculumQuery = Curriculum::query()->where('status', 'active');
        if ($departmentId !== null) {
            $activeCurriculumQuery->where('department_id', $departmentId);
        }
        $activeCurriculumList = $activeCurriculumQuery->get();

        $courseRelations = ['department', 'teachingDepartment', 'teachingProgram', 'program'];

        if ($wants('courses') && $activeCurriculumList->isNotEmpty()) {
            $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
            $activePeriod = match ($activeSemester?->semester) {
                '1st' => 1,
                '2nd' => 2,
                'summer' => 3,
                default => null,
            };
            $pivotData = DB::table('curriculum_course')
                ->whereIn('curriculum_id', $activeCurriculumList->pluck('id'))
                ->when($activePeriod !== null, fn ($query) => $query->where('semester', $activePeriod))
                ->get();
            $activeSemesterCourseIds = $pivotData->pluck('course_id')->map('intval')->unique()->values();
            $configuredFieldCodes = $departmentId === null
                ? []
                : DB::table('field_course_settings')
                    ->where('department_id', $departmentId)
                    ->whereNotNull('course_code')
                    ->pluck('course_code')
                    ->map(static fn ($code): string => SchedulingPolicy::normalizeCourseCode((string) $code))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

            $courses = Course::with($courseRelations)
                // Everything this department teaches, in two disjoint halves.
                ->where(fn ($scope) => $scope
                    // Its own curriculum offerings for the active semester.
                    ->where(fn ($curricular) => $curricular
                        ->whereIn('courses.id', $activeSemesterCourseIds)
                        ->where($this->curricularCourseScope($activeCurriculumList, $departmentId, $configuredFieldCodes)))
                    // Plus every course another college has delegated to it.
                    // This sits outside *both* filters above on purpose: IT's GEC
                    // 101 belongs to an IT curriculum and to the IT department, so
                    // a CAS user matches neither -- yet CAS is the college that
                    // assigns its instructor and needs the course record to say
                    // so. Without it the client's `subjects.find(...)` misses, and
                    // a missing course reads as "open to every department" rather
                    // than "CAS only", so the picker offers the wrong staff.
                    ->when($departmentId !== null, fn ($delegated) => $delegated
                        ->orWhere('teaching_department_id', $departmentId)))
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
            // No active curriculum exists for this department scope. Its own
            // courses are only meaningful in the context of an active curriculum,
            // and shared minors (null dept) have no semester/year-level placement
            // without one either, so neither is returned.
            //
            // Courses delegated to this department are the exception: their
            // placement comes from the owning college's curriculum, and this
            // department has to assign their instructors whether or not it runs a
            // curriculum of its own.
            $courses = ! $wants('courses') || $departmentId === null
                ? collect()
                : Course::with($courseRelations)
                    ->where('teaching_department_id', $departmentId)
                    ->when($facultyProgramId !== null, fn (Builder $query) => $query->where(
                        fn (Builder $programScope) => $programScope
                            ->where('program_id', $facultyProgramId)
                            ->orWhere('teaching_program_id', $facultyProgramId),
                    ))
                    ->orderBy('course_code')
                    ->get();
        }

        $sections = ! $wants('sections') ? collect() : Sections::query()
            // The curriculum comes along so the generator can show which one a
            // year level follows without a second round trip.
            ->with(['department', 'program', 'academicSemester', 'curriculum'])
            // A Department without a Program is not a schedulable academic scope.
            // Keep legacy schedule rows readable below, but do not offer these
            // sections to the active scheduler or generation workflows.
            ->whereHas('program')
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where(function (Builder $q) use ($activeSemesterId, $activeSemester) {
                $q->where('semester_id', $activeSemesterId)
                    ->orWhereNull('semester_id');
                if ($activeSemester && ! empty($activeSemester->semester)) {
                    $q->orWhere('semester', $activeSemester->semester);
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
                'academicSemester:id,academic_year,semester',
                'section:id,section_name,year_level,semester,department_id,program_id,semester_id',
                // teaching_department_id drives the delegated-assignment masking below.
                'course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category,room_type_required,year_level,semester,department_id,teaching_department_id,teaching_program_id,program_id',
                'faculty:id,first_name,last_name,middle_name,department_id,program_id',
                'room:id,room_code,building,room_type,allow_lecture_usage,department_id',
                'department:id,department_name,department_code',
            ]))
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                // This department's own offerings, plus every meeting another
                // college has delegated to it to teach. The delegated half is
                // what the Cross-Department Auto-Assign wizard assigns: IT owns
                // GEC 101 and offers it to an IT section, but CAS teaches it, so
                // a CAS user has to receive IT's rows to be able to staff them.
                // Dropping this scoped the payload to `department_id` alone and
                // left that wizard with nothing to assign.
                fn (Builder $scope) => $scope
                    ->where('department_id', $departmentId)
                    ->orWhereHas(
                        'course',
                        fn ($course) => $course->where('teaching_department_id', $departmentId),
                    ),
            ))
            ->when($facultyProgramId !== null, fn (Builder $query) => $query->whereHas(
                'course',
                fn (Builder $course) => $course
                    ->where('program_id', $facultyProgramId)
                    ->orWhere('teaching_program_id', $facultyProgramId),
            ))
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where('semester_id', $activeSemesterId))
            // The VPAA portal reads the approved timetable only; a department's
            // work in progress and anything still awaiting VPAA action is not
            // part of it. Department users are unaffected.
            ->when(
                ($visibleStatuses = $this->authorization->visibleScheduleStatuses($request)) !== null,
                fn (Builder $query) => $query->whereIn('status', $visibleStatuses),
            )
            ->latest()
            // Keep the default response bounded for institution-wide viewers.
            // Callers that genuinely need more rows can opt in up to 2,000 and
            // should use the paged response mode for larger datasets. One row
            // past the limit is read only to tell the caller the list was cut:
            // the scheduler checks conflicts against these rows, and silently
            // missing ones is worse than saying so.
            ->limit($scheduleLimit + 1)
            ->get();
        $schedulesTruncated = $schedules->count() > $scheduleLimit;
        if ($schedulesTruncated) {
            $schedules = $schedules->take($scheduleLimit)->values();
        }

        $needsSubmissions = $wants('schedules') || $wants('schedule_submissions');
        $scheduleSubmissions = ! $needsSubmissions ? collect() : ScheduleSubmission::query()
            ->with([
                'sections:id,section_name,year_level,department_id,semester_id',
                'submitter:id,name',
                'deanReviewer:id,name',
                'vpaaReviewer:id,name',
                'withdrawer:id,name',
            ])
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where('semester_id', $activeSemesterId))
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
            'active_semester' => $activeSemester,
            // The grid window is a stored setting (schedule_settings, PATCH
            // /timeslots/settings). The client used to hardcode 07:00-19:00 in ~40
            // places, so changing it desynchronised the whole builder (audit #33).
            'time_grid' => [
                'opening_time' => substr(SchedulingPolicy::openingTime(), 0, 5),
                'closing_time' => substr(SchedulingPolicy::closingTime(), 0, 5),
                'field_end_time' => substr(SchedulingPolicy::fieldDayEndTime(), 0, 5),
                'slot_minutes' => SchedulingPolicy::SLOT_MINUTES,
                'slot_count' => SchedulingPolicy::totalSlots(),
            ],
            'rooms' => $rooms,
            'courses' => $courses,
            // Department-wide schedulers may use the external-instructor tab. A
            // Program Head, however, owns one program roster and must never see
            // another program's instructors in Auto-Assign.
            'faculties' => $wants('faculties')
                ? $this->facultyLoad->get($facultyDepartmentId, $activeSemesterId, $facultyProgramId)
                : collect(),
            'sections' => $sections,
            'schedules' => $schedules,
            'schedules_truncated' => $schedulesTruncated,
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
