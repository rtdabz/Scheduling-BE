<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\ScheduleSignatories;
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
use Illuminate\Support\Str;

class InitialDataController extends Controller
{
    private const CACHE_TTL_SECONDS = ApiCache::LOOKUP_TTL_SECONDS;

    /** @var array<string, int> totals of the lists paginated by the database for the current payload */
    private array $sqlPageTotals = [];

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
        private readonly ScheduleSignatories $signatories,
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
            'schedule_section_id' => self::scheduleSectionId($request),
            'schedule_occupancy' => self::wantsOccupancy($request),
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

    /** Presentation state only; historical submissions and validation keep their original statuses. */
    private function sectionsResetSinceSubmission(Collection $sections, ?int $semesterId): array
    {
        if ($semesterId === null) {
            return [];
        }

        $remaining = array_fill_keys($sections->pluck('id')->all(), true);
        $reset = [];
        $events = SchedulingAuditLog::query()->where('semester_id', $semesterId)
            ->whereIn('action', ['schedule_reset', 'schedule_submitted', 'schedule_returned_by_dean', 'schedule_returned_by_vpaa', 'schedule_withdrawn'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['action', 'metadata', 'section_id']);
        foreach ($events as $event) {
            $ids = $event->metadata['section_ids'] ?? $event->metadata['selected_section_ids'] ?? [$event->section_id];
            foreach ($ids as $id) {
                $id = (int) $id;
                if (! isset($remaining[$id])) {
                    continue;
                }
                unset($remaining[$id]);
                if ($event->action === 'schedule_reset') {
                    $reset[$id] = true;
                }
            }
            if ($remaining === []) {
                break;
            }
        }

        return $reset;
    }

    private function buildPayload(Request $request): array
    {
        $include = $this->requestedSections($request);
        $wants = static fn (string $section): bool => $include === null
            || in_array($section, $include, true);

        $pageSize = min(max((int) $request->query('per_page', 0), 0), 500);
        $scheduleLimit = min(max((int) $request->query('schedule_limit', 500), 1), 2000);
        $this->sqlPageTotals = [];
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
        // The owner's own times that are lent out: reserved, so its editor flags them before saving.
        $lentWindows = $departmentId !== null && $activeSemesterId !== null && $wants('rooms')
            ? app(RoomAccessPolicy::class)->lentWindowsFor((int) $departmentId, (int) $activeSemesterId)
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
            ->tap(fn (Builder $query) => $this->paginateInSql($query, 'rooms', $request, $pageSize))
            ->get()
            ->each(function (Rooms $room) use ($grantWindows, $lentWindows): void {
                if (isset($lentWindows[(int) $room->id])) {
                    $room->setAttribute('lent_windows', array_map(
                        static fn (array $window): array => [
                            'day' => $window['day'],
                            'start_time' => substr($window['start_time'], 0, 5),
                            'end_time' => substr($window['end_time'], 0, 5),
                            'borrower' => $window['borrower'],
                        ],
                        $lentWindows[(int) $room->id],
                    ));
                }
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
        // Courses whose returned sections were handed to this department (or program) one by
        // one: the course-level teaching columns do not name it, but its classes do.
        $sectionLevelCourseIds = $departmentId === null || $activeSemester === null
            ? collect()
            : Schedule::query()
                ->where('semester_id', $activeSemester->id)
                ->whereNotNull('delegation_returned_from_department_id')
                ->where('section_teaching_department_id', $departmentId)
                ->when($facultyProgramId !== null, fn ($query) => $query->where('section_teaching_program_id', $facultyProgramId))
                ->distinct()
                ->pluck('course_id')
                ->map('intval');

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
                        ->orWhere('teaching_department_id', $departmentId)
                        ->orWhereIn('courses.id', $sectionLevelCourseIds)))
                ->when($facultyProgramId !== null, fn (Builder $query) => $query->where(
                    fn (Builder $programScope) => $programScope
                        ->where('program_id', $facultyProgramId)
                        ->orWhere('teaching_program_id', $facultyProgramId)
                        ->orWhereIn('courses.id', $sectionLevelCourseIds)
                        ->orWhereHas('curriculum', fn ($curriculum) => $curriculum->whereIn(
                            'curriculum.id',
                            $activeCurriculumList->where('program_id', $facultyProgramId)->pluck('id'),
                        )),
                ))
                ->get();

            $pivotMap = [];
            $placements = [];
            $classMinutes = [];
            foreach ($pivotData as $p) {
                if (! isset($pivotMap[$p->course_id])) {
                    $pivotMap[$p->course_id] = $p;
                }
                $placements[$p->course_id][] = [
                    'curriculum_id' => (int) $p->curriculum_id,
                    'year_level' => (string) $p->year_level,
                    'semester' => $p->semester == 1 ? '1st' : ($p->semester == 2 ? '2nd' : 'summer'),
                ];
                if ($p->lecture_minutes !== null || $p->laboratory_minutes !== null) {
                    $classMinutes[$p->course_id][(int) $p->curriculum_id] = [
                        'lecture' => $p->lecture_minutes === null ? null : (int) $p->lecture_minutes,
                        'laboratory' => $p->laboratory_minutes === null ? null : (int) $p->laboratory_minutes,
                    ];
                }
            }

            $curricularIds = $activeSemesterCourseIds->flip();
            $courses = $courses->map(function ($c) use ($pivotMap, $placements, $classMinutes, $curricularIds, $departmentId, $configuredFieldCodes) {
                // Every active curriculum's placement, so each section reads the one it follows.
                $c->setAttribute('curriculum_placements', $placements[$c->id] ?? []);
                // Class minutes each curriculum recorded on import; scheduling sizes the course from them.
                $c->setAttribute('class_minutes_by_curriculum', (object) ($classMinutes[$c->id] ?? []));
                $c->setAttribute('delegated_only', $departmentId !== null && ! (
                    $curricularIds->has((int) $c->id)
                    && ($c->department_id === null
                        || (int) $c->department_id === $departmentId
                        || in_array($c->course_code, $configuredFieldCodes, true))
                ));
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
                    ->where(fn (Builder $delegated) => $delegated
                        ->where(fn (Builder $courseLevel) => $courseLevel
                            ->where('teaching_department_id', $departmentId)
                            ->when($facultyProgramId !== null, fn (Builder $query) => $query->where(
                                fn (Builder $programScope) => $programScope
                                    ->where('program_id', $facultyProgramId)
                                    ->orWhere('teaching_program_id', $facultyProgramId),
                            )))
                        ->orWhereIn('courses.id', $sectionLevelCourseIds))
                    ->orderBy('course_code')
                    ->get()
                    ->each(fn (Course $course) => $course->setAttribute('delegated_only', true));
        }

        $sections = ! $wants('sections') ? collect() : Sections::query()
            ->with(['department', 'program', 'academicSemester', 'curriculum'])
            ->whereHas('program')
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($facultyProgramId !== null, fn (Builder $query) => $query->where('program_id', $facultyProgramId))
            // The active semester's sections, plus legacy sections never linked to a semester. Matching the
            // "1st"/"2nd" label as well pulled in same-named sections from other academic years.
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('semester_id', $activeSemesterId)->orWhereNull('semester_id'),
            ))
            ->tap(fn (Builder $query) => $this->paginateInSql($query, 'sections', $request, $pageSize))
            ->get();
        if ($sections->isNotEmpty()) {
            $resetSections = $this->sectionsResetSinceSubmission($sections, $activeSemesterId);
            $sectionStatuses = $this->submissionStatuses->forSections(
                $sections->pluck('id')->map('intval')->all(),
                $activeSemesterId,
            );
            $sections->each(function (Sections $section) use ($sectionStatuses, $resetSections): void {
                $status = $sectionStatuses[(int) $section->id] ?? null;
                $section->setAttribute('submission_status', $status['submission_status'] ?? SubmissionStatusResolver::DRAFT);
                $section->setAttribute('revision_status', $status['revision_status'] ?? SubmissionStatusResolver::INITIAL);
                $section->setAttribute('submission_revision_number', $status['revision_number'] ?? null);
                $section->setAttribute('schedule_reset_since_submission', isset($resetSections[(int) $section->id]));
            });
        }

        // One scope for every list of meetings this payload carries, so the full rows, the selected
        // section's rows and the conflict occupancy always agree on what the viewer may see.
        $visibleStatuses = $this->authorization->visibleScheduleStatuses($request);
        $scheduleScope = fn (): Builder => Schedule::query()
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope
                    ->where('department_id', $departmentId)
                    ->orWhere(fn (Builder $delegated) => $delegated->delegatedTo($departmentId)),
            ))
            ->tap(fn (Builder $query) => $this->authorization->scopeSchedulesToProgram($query, $request))
            ->when($activeSemesterId !== null, fn (Builder $query) => $query->where('semester_id', $activeSemesterId))
            ->when($visibleStatuses !== null, fn (Builder $query) => $query->whereIn('status', $visibleStatuses));
        $scheduleRelations = [
            'academicSemester:id,academic_year,semester',
            'section:id,section_name,year_level,semester,department_id,program_id,semester_id',
            'section.program:id,code',
            'course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category,room_type_required,year_level,semester,department_id,teaching_department_id,teaching_program_id,program_id',
            'faculty:id,first_name,last_name,middle_name,suffix,honorific_prefix,credentials,department_id,program_id',
            'room:id,room_code,building,room_type,allow_lecture_usage,department_id',
            'department:id,department_name,department_code',
        ];

        $schedulesTruncated = false;
        $scheduleOccupancy = null;
        if (! $wants('schedules')) {
            $schedules = collect();
        } elseif ($pageSize > 0) {
            // A paginated client walks every page, so there is no cut-off to report.
            $schedules = $scheduleScope()->with($scheduleRelations)->latest()->orderByDesc('id')
                ->tap(fn (Builder $query) => $this->paginateInSql($query, 'schedules', $request, $pageSize))
                ->get();
        } else {
            $schedules = $scheduleScope()->with($scheduleRelations)->latest()->limit($scheduleLimit + 1)->get();
            $schedulesTruncated = $schedules->count() > $scheduleLimit;
            if ($schedulesTruncated) {
                $schedules = $schedules->take($scheduleLimit)->values();
                $loadedIds = array_flip($schedules->pluck('id')->map('intval')->all());

                // The section open in the scheduler is always complete, however old its meetings are.
                if (($sectionId = self::scheduleSectionId($request)) !== null) {
                    $sectionRows = $scheduleScope()->with($scheduleRelations)
                        ->where('section_id', $sectionId)
                        ->latest()
                        ->get()
                        ->reject(fn (Schedule $schedule): bool => isset($loadedIds[(int) $schedule->id]));
                    foreach ($sectionRows as $row) {
                        $loadedIds[(int) $row->id] = true;
                    }
                    $schedules = $schedules->concat($sectionRows)->values();
                }

                if (self::wantsOccupancy($request)) {
                    $scheduleOccupancy = $this->occupancyRows($scheduleScope, $loadedIds, $viewerDepartmentId);
                }
            }
        }
        $schedules->each(fn (Schedule $schedule) => $schedule->makeHidden(['split', 'created_at', 'updated_at', 'deleted_at']));

        // The approval screens list every revision, so the history is loaded in full only when the
        // response carries it. Otherwise only each section's latest submission is needed, to label its rows.
        $returnsSubmissions = $wants('schedule_submissions');
        $submissionsQuery = fn (): Builder => ScheduleSubmission::query()
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
            ->orderByDesc('revision_number');
        if ($returnsSubmissions) {
            $scheduleSubmissions = $submissionsQuery()->get();
        } elseif ($wants('schedules') && $schedules->isNotEmpty()) {
            $latestIds = $this->latestSubmissionIdsBySection(
                $departmentId,
                $activeSemesterId,
                $schedules->pluck('section_id')->filter()->map('intval')->unique()->values()->all(),
            );
            $scheduleSubmissions = $latestIds === []
                ? collect()
                : $submissionsQuery()->whereKey(array_values(array_unique($latestIds)))->get();
        } else {
            $scheduleSubmissions = collect();
        }
        if ($returnsSubmissions) {
            $revisionStatuses = $this->submissionStatuses->forSubmissions($scheduleSubmissions);
            $scheduleSubmissions->each(fn (ScheduleSubmission $submission) => $submission->setAttribute(
                'revision_status',
                $revisionStatuses[$submission->id] ?? SubmissionStatusResolver::INITIAL,
            ));
        }
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
            // Once submitted, a schedule prints the officials recorded on its submission. A draft or a
            // recalled schedule back in revision has no signed version yet, so it prints the current officials.
            if (! in_array($schedule->status, ScheduleSignatories::UNSIGNED_SCHEDULE_STATUSES, true)) {
                $schedule->setAttribute('signatories_submission_id', $submission->id);
            }
        });

        // The officials printed in each submission's signature block, as recorded by the workflow.
        // Resolving one can query the current officials, so only blocks the response shows are resolved.
        $referencedSubmissionIds = $schedules->pluck('signatories_submission_id')->filter()
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);
        $scheduleSubmissions->each(function (ScheduleSubmission $submission) use ($returnsSubmissions, $referencedSubmissionIds): void {
            if ($returnsSubmissions || $referencedSubmissionIds->has((int) $submission->id)) {
                $submission->setAttribute('signatories', $this->signatories->resolve($submission));
            }
            foreach (['submitter' => 'submitted_by', 'deanReviewer' => 'dean_reviewed_by', 'vpaaReviewer' => 'vpaa_reviewed_by', 'withdrawer' => 'withdrawn_by'] as $relation => $column) {
                $submission->unsetRelation($relation)->setAttribute(Str::snake($relation), $submission->actor_snapshots[$column] ?? null);
            }
        });

        $schedules->each(function ($schedule) use ($viewerDepartmentId): void {
            if (SchedulingPolicy::delegatedAwayTo($schedule) !== null
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
                ->where('role', 'dean')->where('is_active', true)
                ->select('id', 'name', 'department_id')])
            ->latest()
            ->tap(fn (Builder $query) => $this->paginateInSql($query, 'departments', $request, $pageSize))
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
            // Keyed by the submission each row names in `signatories_submission_id`; nothing else reads it.
            'schedule_signatories' => $scheduleSubmissions
                ->filter(static fn (ScheduleSubmission $submission): bool => $referencedSubmissionIds->has((int) $submission->id))
                ->mapWithKeys(static fn (ScheduleSubmission $submission): array => [$submission->id => $submission->signatories]),
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
            'users' => ! $wants('users') ? collect() : User::query()->where('is_active', true)
                ->when($departmentId !== null, fn (Builder $query) => $query->where(
                    fn (Builder $scope) => $scope
                        ->where('department_id', $departmentId)
                        ->orWhere('role', 'vpaa'),
                ))
                ->latest()
                ->tap(fn (Builder $query) => $this->paginateInSql($query, 'users', $request, $pageSize))
                ->get(['id', 'name', 'role', 'department_id', 'program_id', 'is_active']),
        ];
        if ($scheduleOccupancy !== null) {
            $payload['schedule_occupancy'] = $scheduleOccupancy;
        }

        if ($pageSize > 0) {
            foreach (['rooms', 'courses', 'sections', 'schedules', 'departments', 'users'] as $key) {
                $items = collect($payload[$key] ?? []);
                $page = self::requestedPage($request, $key);
                // Lists the database already paginated carry their own total; courses are merged and
                // sorted in PHP, so they are still paginated here.
                $pagedInSql = array_key_exists($key, $this->sqlPageTotals);
                $total = $pagedInSql ? $this->sqlPageTotals[$key] : $items->count();
                $payload[$key] = [
                    'data' => ($pagedInSql ? $items : $items->forPage($page, $pageSize))->values(),
                    'meta' => [
                        'page' => $page,
                        'per_page' => $pageSize,
                        'total' => $total,
                        'last_page' => max(1, (int) ceil($total / $pageSize)),
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

    /** Page a list in the query itself, remembering its total for the response's pagination meta. */
    private function paginateInSql(Builder $query, string $key, Request $request, int $pageSize): void
    {
        if ($pageSize <= 0) {
            return;
        }
        $this->sqlPageTotals[$key] = (clone $query)->toBase()->getCountForPagination();
        // A stable order, so consecutive pages neither repeat nor skip rows.
        $query->orderBy($query->getModel()->getQualifiedKeyName())
            ->forPage(self::requestedPage($request, $key), $pageSize);
    }

    private static function requestedPage(Request $request, string $key): int
    {
        return max(1, (int) $request->query($key.'_page', 1));
    }

    private static function scheduleSectionId(Request $request): ?int
    {
        $sectionId = (int) $request->query('schedule_section_id', 0);

        return $sectionId > 0 ? $sectionId : null;
    }

    private static function wantsOccupancy(Request $request): bool
    {
        return filter_var($request->query('schedule_occupancy', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The latest submission of each section, found from the pivot without loading the history.
     *
     * @param  list<int>  $sectionIds
     * @return array<int, int> submission id by section id
     */
    private function latestSubmissionIdsBySection(?int $departmentId, ?int $semesterId, array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }
        $latest = [];
        foreach (array_chunk($sectionIds, 500) as $chunk) {
            DB::table('schedule_submission_sections as pivot')
                ->join('schedule_submissions as submission', 'submission.id', '=', 'pivot.schedule_submission_id')
                ->whereIn('pivot.section_id', $chunk)
                ->when($departmentId !== null, fn ($query) => $query->where('submission.department_id', $departmentId))
                ->when($semesterId !== null, fn ($query) => $query->where('submission.semester_id', $semesterId))
                ->orderByDesc('submission.revision_number')
                ->get(['pivot.section_id', 'submission.id'])
                ->each(function (object $row) use (&$latest): void {
                    $latest[(int) $row->section_id] ??= (int) $row->id;
                });
        }

        return $latest;
    }

    /**
     * Every other visible meeting, reduced to what the scheduler's conflict check reads. Rows beyond
     * `schedule_limit` still occupy rooms, instructors and sections, so leaving them out let the grid
     * offer slots the server would then reject.
     *
     * @param  Closure(): Builder  $scheduleScope
     * @param  array<int, true>  $loadedIds  rows already returned in full
     * @return list<array<string, mixed>>
     */
    private function occupancyRows(Closure $scheduleScope, array $loadedIds, ?int $viewerDepartmentId): array
    {
        $rows = $scheduleScope()
            ->orderBy('schedules.id')
            ->toBase()
            ->get([
                'schedules.id', 'schedules.semester_id', 'schedules.department_id', 'schedules.section_id',
                'schedules.course_id', 'schedules.faculty_id', 'schedules.room_id', 'schedules.day',
                'schedules.start_time', 'schedules.end_time', 'schedules.mode', 'schedules.is_hybrid',
                'schedules.preferred_pattern', 'schedules.faculty_assignment_done', 'schedules.status',
                'schedules.delegation_returned_from_department_id',
                'schedules.section_teaching_department_id', 'schedules.section_teaching_program_id',
            ])
            ->reject(static fn (object $row): bool => isset($loadedIds[(int) $row->id]))
            ->values();
        if ($rows->isEmpty()) {
            return [];
        }

        $splits = ScheduleSplit::query()
            ->whereIn('schedule_id', $scheduleScope()->select('schedules.id'))
            ->get(['schedule_id', 'split_group_id', 'meeting_type', 'meeting_index'])
            ->keyBy('schedule_id');
        $teachingDepartmentByCourse = Course::query()
            ->whereIn('id', $rows->pluck('course_id')->filter()->unique()->values())
            ->pluck('teaching_department_id', 'id');
        $virtualRoomCodes = Rooms::query()
            ->whereIn('id', $rows->pluck('room_id')->filter()->unique()->values())
            ->whereIn('room_code', ['ONLINE', 'FIELD'])
            ->pluck('room_code', 'id');

        return $rows->map(static function (object $row) use ($splits, $teachingDepartmentByCourse, $virtualRoomCodes, $viewerDepartmentId): array {
            // Per class: a returned section is taught by its own section-level department.
            $teachingDepartmentId = $row->delegation_returned_from_department_id !== null
                ? $row->section_teaching_department_id
                : ($teachingDepartmentByCourse[$row->course_id] ?? null);
            // Same rule as the full rows: a borrowed class's instructor stays hidden from the owning
            // department until the teaching department finishes assigning it.
            $facultyHidden = $teachingDepartmentId !== null
                && (int) $teachingDepartmentId !== (int) $row->department_id
                && $viewerDepartmentId !== null
                && (int) $viewerDepartmentId === (int) $row->department_id
                && ! (bool) $row->faculty_assignment_done;
            $split = $splits->get($row->id);

            return [
                'id' => (int) $row->id,
                'semester_id' => $row->semester_id === null ? null : (int) $row->semester_id,
                'department_id' => $row->department_id === null ? null : (int) $row->department_id,
                'section_id' => $row->section_id === null ? null : (int) $row->section_id,
                'course_id' => $row->course_id === null ? null : (int) $row->course_id,
                'faculty_id' => $facultyHidden || $row->faculty_id === null ? null : (int) $row->faculty_id,
                'room_id' => $row->room_id === null ? null : (int) $row->room_id,
                'room' => isset($virtualRoomCodes[$row->room_id]) ? ['room_code' => $virtualRoomCodes[$row->room_id]] : null,
                'day' => $row->day,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'mode' => $row->mode,
                'is_hybrid' => (bool) $row->is_hybrid,
                'preferred_pattern' => $row->preferred_pattern,
                'faculty_assignment_done' => (bool) $row->faculty_assignment_done,
                'delegation_returned_from_department_id' => $row->delegation_returned_from_department_id === null ? null : (int) $row->delegation_returned_from_department_id,
                'section_teaching_department_id' => $row->section_teaching_department_id === null ? null : (int) $row->section_teaching_department_id,
                'section_teaching_program_id' => $row->section_teaching_program_id === null ? null : (int) $row->section_teaching_program_id,
                'status' => $row->status,
                'split_group_id' => $split?->split_group_id,
                'meeting_type' => $split?->meeting_type,
                'meeting_index' => $split === null ? null : (int) $split->meeting_index,
            ];
        })->all();
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
