<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
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
use App\Services\Scheduling\Submission\SubmissionStatusResolver;
use App\Services\Scheduling\Support\DepartmentCourseRules;
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
    private const CACHE_TTL_SECONDS = ApiCache::LOOKUP_TTL_SECONDS;

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
        private readonly SubmissionStatusResolver $submissionStatuses,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $include = $this->requestedSections($request);
        $requestedGroups = $include ?? self::OPTIONAL_SECTIONS;
        if (in_array('sections', $requestedGroups, true)) {
            $requestedGroups = array_values(array_unique([...$requestedGroups, 'schedules', 'schedule_submissions']));
        }
        $sectionGroups = array_map(
            static fn (string $section): string => 'initial.data.'.$section,
            $requestedGroups,
        );
        $cacheKey = ApiCache::compositeKey('initial.data', $sectionGroups, [
            'include' => $include,
            'role' => (string) ($user?->role ?? ''),
            'department_id' => $user?->isVpaa() || $user?->department_id === null
                ? null
                : (int) $user?->department_id,
            'program_id' => $user?->role === 'program_head' ? (int) ($user?->program_id ?? 0) : null,
            'approval_queue' => $this->authorization->visibleScheduleStatuses($request),
            'per_page' => min(max((int) $request->query('per_page', 0), 0), 500),
            'schedule_limit' => min(max((int) $request->query('schedule_limit', 500), 1), 2000),
            'pages' => collect(['rooms', 'courses', 'sections', 'schedules', 'departments', 'users'])
                ->mapWithKeys(fn (string $key): array => [$key => (int) $request->query($key.'_page', 1)])
                ->all(),
        ]);

        $json = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): string => json_encode(
                $this->buildPayload($request),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );

        $json = substr(rtrim($json), 0, -1)
            .',"can_edit_program_ids":'.json_encode($this->authorization->writableProgramIds($request)).'}';

        return JsonResponse::fromJsonString($json);
    }

    /**
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
            ->tap(fn (Builder $query) => $this->authorization->scopeRoomsToProgram($query, $request))
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
                : DepartmentCourseRules::fieldCourseCodes((int) $departmentId);

            $courses = Course::with($courseRelations)
                ->where(fn ($scope) => $scope
                    ->where(fn ($curricular) => $curricular
                        ->whereIn('courses.id', $activeSemesterCourseIds)
                        ->where($this->curricularCourseScope($activeCurriculumList, $departmentId, $configuredFieldCodes)))
                    ->when($departmentId !== null, fn ($delegated) => $delegated
                        ->orWhere('teaching_department_id', $departmentId)))
                ->when($facultyProgramId !== null, fn (Builder $query) => $query->where(
                    fn (Builder $programScope) => $programScope
                        ->where('program_id', $facultyProgramId)
                        ->orWhere('teaching_program_id', $facultyProgramId)
                        ->orWhereHas('curriculum', fn ($curriculum) => $curriculum->whereIn(
                            'curriculum.id',
                            $activeCurriculumList->where('program_id', $facultyProgramId)->pluck('id'),
                        )),
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
            ->with(['department', 'program', 'academicSemester', 'curriculum'])
            ->whereHas('program')
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($facultyProgramId !== null, fn (Builder $query) => $query->where('program_id', $facultyProgramId))
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where(function (Builder $q) use ($activeSemesterId, $activeSemester) {
                $q->where('semester_id', $activeSemesterId)
                    ->orWhereNull('semester_id');
                if ($activeSemester && ! empty($activeSemester->semester)) {
                    $q->orWhere('semester', $activeSemester->semester);
                }
            }))
            ->get();
        if ($sections->isNotEmpty()) {
            $sectionStatuses = $this->submissionStatuses->forSections(
                $sections->pluck('id')->map('intval')->all(),
                $activeSemesterId,
            );
            $sections->each(function (Sections $section) use ($sectionStatuses): void {
                $status = $sectionStatuses[(int) $section->id] ?? null;
                $section->setAttribute('submission_status', $status['submission_status'] ?? SubmissionStatusResolver::DRAFT);
                $section->setAttribute('revision_status', $status['revision_status'] ?? SubmissionStatusResolver::INITIAL);
                $section->setAttribute('submission_revision_number', $status['revision_number'] ?? null);
            });
        }

        $schedules = ! $wants('schedules') ? collect() : Schedule::query()
            ->with(array_filter([
                'academicSemester:id,academic_year,semester',
                'section:id,section_name,year_level,semester,department_id,program_id,semester_id',
                'section.program:id,code',
                'course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category,room_type_required,year_level,semester,department_id,teaching_department_id,teaching_program_id,program_id',
                'faculty:id,first_name,last_name,middle_name,department_id,program_id',
                'room:id,room_code,building,room_type,allow_lecture_usage,department_id',
                'department:id,department_name,department_code',
            ]))
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope
                    ->where('department_id', $departmentId)
                    ->orWhereHas(
                        'course',
                        fn ($course) => $course->where('teaching_department_id', $departmentId),
                    ),
            ))
            ->tap(fn (Builder $query) => $this->authorization->scopeSchedulesToProgram($query, $request))
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where('semester_id', $activeSemesterId))
            ->when(
                ($visibleStatuses = $this->authorization->visibleScheduleStatuses($request)) !== null,
                fn (Builder $query) => $query->whereIn('status', $visibleStatuses),
            )
            ->latest()
            ->limit($scheduleLimit + 1)
            ->get();
        $schedulesTruncated = $schedules->count() > $scheduleLimit;
        if ($schedulesTruncated) {
            $schedules = $schedules->take($scheduleLimit)->values();
        }
        $schedules->each(fn (Schedule $schedule) => $schedule->makeHidden(['split', 'created_at', 'updated_at', 'deleted_at']));

        $needsSubmissions = $wants('schedules') || $wants('schedule_submissions');
        $scheduleSubmissions = ! $needsSubmissions ? collect() : ScheduleSubmission::query()
            ->with([
                'sections:id,section_name,year_level,department_id,semester_id,program_id',
                'sections.program:id,code,name,major',
                'submitter:id,name',
                'deanReviewer:id,name',
                'vpaaReviewer:id,name',
                'withdrawer:id,name',
            ])
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where('semester_id', $activeSemesterId))
            ->orderByDesc('revision_number')
            ->get();
        $revisionStatuses = $this->submissionStatuses->forSubmissions($scheduleSubmissions);
        $scheduleSubmissions->each(fn (ScheduleSubmission $submission) => $submission->setAttribute(
            'revision_status',
            $revisionStatuses[$submission->id] ?? SubmissionStatusResolver::INITIAL,
        ));
        $latestSubmissionBySection = collect();
        foreach ($scheduleSubmissions as $submission) {
            foreach ($submission->sections as $submissionSection) {
                $sectionId = (int) $submissionSection->id;
                if (! $latestSubmissionBySection->has($sectionId)) {
                    $latestSubmissionBySection->put($sectionId, $submission);
                }
            }
        }

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

        $schedules->each(function ($schedule) use ($viewerDepartmentId): void {
            $course = $schedule->course;
            if ($course?->teaching_department_id !== null
                && (int) $course->teaching_department_id !== (int) $schedule->department_id
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
            'time_grid' => [
                'opening_time' => substr(SchedulingPolicy::openingTime(), 0, 5),
                'closing_time' => substr(SchedulingPolicy::closingTime(), 0, 5),
                'field_end_time' => substr(SchedulingPolicy::fieldDayEndTime(), 0, 5),
                'slot_minutes' => SchedulingPolicy::SLOT_MINUTES,
                'slot_count' => SchedulingPolicy::totalSlots(),
            ],
            'rooms' => self::withoutNestedLogos($rooms, 'department'),
            'courses' => self::withoutNestedLogos($courses, 'department', 'teachingDepartment'),
            'faculties' => $wants('faculties')
                ? self::withoutNestedLogos(
                    $this->facultyLoad->get($facultyDepartmentId, $activeSemesterId, $facultyProgramId),
                    'department',
                )
                : collect(),
            'sections' => self::withoutNestedLogos($sections, 'department'),
            'schedules' => $schedules,
            'schedules_truncated' => $schedulesTruncated,
            'schedule_submissions' => $scheduleSubmissions,
            'departments' => $departments,
            'active_curricula_count' => $activeCurriculumList->count(),
            'scheduling_ready' => $departmentId === null || Departments::query()->whereKey($departmentId)->whereHas('programs')->exists(),
            'has_dean' => $departmentId === null || User::query()
                ->where('role', 'dean')
                ->where('department_id', $departmentId)
                ->where('is_active', true)
                ->exists(),
            'field_course_assignment_enabled' => SchedulingPolicy::fieldCourseSettingEnabled($departmentId),
            'field_course_codes' => array_keys(SchedulingPolicy::fieldCourseCodeMap($departmentId)),
            'users' => ! $wants('users') ? collect() : User::query()
                ->when($departmentId !== null, fn (Builder $query) => $query->where(
                    fn (Builder $scope) => $scope
                        ->where('department_id', $departmentId)
                        ->orWhere('role', 'vpaa'),
                ))
                ->latest()
                ->get(['id', 'name', 'role', 'department_id', 'program_id']),
        ];

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

    private static function withoutNestedLogos(Collection $models, string ...$relations): Collection
    {
        return $models->each(function ($model) use ($relations): void {
            foreach ($relations as $relation) {
                if ($model->relationLoaded($relation)) {
                    $model->getRelation($relation)?->makeHidden('logo');
                }
            }
        });
    }

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
            ->sort()
            ->values()
            ->all();

        return $requested === [] ? null : $requested;
    }

}
