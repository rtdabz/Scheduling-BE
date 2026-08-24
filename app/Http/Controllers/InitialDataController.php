<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\DepartmentResourceSlotLimitService;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

class InitialDataController extends Controller
{
    private ?bool $hasCourseCategories = null;

    public function __construct(
        private readonly FacultyLoadService $facultyLoad,
        private readonly DepartmentResourceSlotLimitService $resourceLimits,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $pageSize = min(max((int) $request->query('per_page', 0), 0), 500);
        $user = $request->user();
        $departmentId = $user->isVpaa() || $user->department_id === null
            ? null
            : (int) $user->department_id;
        $viewerDepartmentId = $departmentId;
        $facultyDepartmentId = $user->role === 'program_head' ? $departmentId : null;
        $facultyProgramId = $user->role === 'program_head' ? (int) ($user->program_id ?? 0) : null;
        $activeTerm = Cache::remember('scheduler:term:active', 300, fn () => Terms::query()->where('is_active', true)->first());
        $activeTermId = $activeTerm?->id;
        // Cached per request: these hit information_schema, and the schema cannot
        // change between the two reads below (audit finding #10).
        $hasCourseCategories = $this->hasCourseCategoryTables();

        $rooms = Rooms::query()
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
        if ($hasCourseCategories) {
            $courseRelations[] = 'categories';
        }

        if ($activeCurriculumList->isNotEmpty()) {
            $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];

            $courses = Course::with($courseRelations)
                ->where(function ($outer) use ($activeCurriculumList, $departmentId) {
                    $outer->where(function ($own) use ($activeCurriculumList, $departmentId) {
                        $own->whereHas('curriculum', function ($q) use ($activeCurriculumList) {
                            $q->whereIn('curriculum.id', $activeCurriculumList->pluck('id'));
                        })
                            // Only include courses that belong to this department or are shared minors (null dept)
                            ->when($departmentId !== null, function ($q) use ($departmentId) {
                                $q->where(function ($scope) use ($departmentId) {
                                    $scope->whereNull('department_id')
                                          ->orWhere('department_id', $departmentId);
                                });
                            });
                    });

                    // Plus every course another college has delegated to this one to
                    // teach. This has to sit outside *both* filters above: IT's GEC
                    // 101 belongs to an IT curriculum and to the IT department, so a
                    // CAS user matches neither — yet CAS is the college that assigns
                    // its instructor and needs the course record to say so.
                    //
                    // Without this the client's `subjects.find(...)` misses, and a
                    // missing course reads as "open to every department" rather than
                    // "CAS only", so the picker would silently offer the wrong staff.
                    if ($departmentId !== null) {
                        $outer->orWhere('teaching_department_id', $departmentId);
                    }
                })
                ->when($facultyProgramId !== null, fn (Builder $query) => $query->where(
                    fn (Builder $programScope) => $programScope
                        ->where('program_id', $facultyProgramId)
                        ->orWhere('teaching_program_id', $facultyProgramId),
                ))
                ->get();

            $pivotData = \DB::table('curriculum_course')
                ->whereIn('curriculum_id', $activeCurriculumList->pluck('id'))
                ->get();

            $pivotMap = [];
            foreach ($pivotData as $p) {
                if (!isset($pivotMap[$p->course_id])) {
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
                if ($yA !== $yB) return $yA <=> $yB;

                $sA = $semOrder[$a->semester ?? ''] ?? 99;
                $sB = $semOrder[$b->semester ?? ''] ?? 99;
                if ($sA !== $sB) return $sA <=> $sB;

                $catA = strtolower($a->course_category ?? '') === 'major' ? 1 : 2;
                $catB = strtolower($b->course_category ?? '') === 'major' ? 1 : 2;
                if ($catA !== $catB) return $catA <=> $catB;

                return strcmp($a->course_code ?? '', $b->course_code ?? '');
            })->values();
        } else {
            // No active curriculum exists for this department scope.
            // Return an empty list — courses are only meaningful in the context of an
            // active curriculum. Shared minors (null dept) are not included here either,
            // because without a curriculum they have no term/year-level placement.
            //
            // Courses delegated to this department are the exception: their placement
            // comes from the owning college's curriculum, and this department has to
            // assign their instructors whether or not it runs a curriculum of its own.
            $courses = $departmentId === null
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

        $sections = Sections::query()
            ->with(['department', 'term'])
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeTermId !== null, fn (Builder $query) => $query->where(function (Builder $q) use ($activeTermId, $activeTerm) {
                $q->where('term_id', $activeTermId)
                  ->orWhereNull('term_id');
                if ($activeTerm && !empty($activeTerm->semester)) {
                    $q->orWhere('semester', $activeTerm->semester);
                }
            }))
            ->get();

        $schedules = Schedule::query()
            ->with(array_filter([
                'term',
                'section',
                $hasCourseCategories ? 'course.categories' : 'course',
                'faculty',
                'room',
                'department',
            ]))
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                // Own offerings, plus those another college delegated to this one to
                // teach. Auto-Assign works from these rows, so the teaching college
                // cannot assign what it cannot see. An explicit override is the only
                // thing that widens this — a course this department owns already
                // matches on `department_id`.
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
            ->when($activeTermId !== null, fn (Builder $query) => $query->where('term_id', $activeTermId))
            ->latest()
            // Keep the default response bounded for institution-wide viewers.
            // Callers that genuinely need more rows can opt in up to 2,000 and
            // should use the paged response mode for larger datasets.
            ->limit(min(max((int) $request->query('schedule_limit', 500), 1), 2000))
            ->get();

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

        $departments = Departments::query()
            ->withCount(['rooms', 'sections', 'faculties'])
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
            'faculties' => $this->facultyLoad->get($facultyDepartmentId, $activeTermId, $facultyProgramId),
            'sections' => $sections,
            'schedules' => $schedules,
            'departments' => $departments,
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
            'users' => User::query()
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

        return response()->json($payload);
    }

    /**
     * Whether the optional course-category tables are present.
     *
     * Memoized for the request: two Schema::hasTable calls per request each hit
     * information_schema, and the answer cannot change mid-request.
     */
    private function hasCourseCategoryTables(): bool
    {
        return $this->hasCourseCategories ??= Schema::hasTable('course_categories')
            && Schema::hasTable('course_category_mapping');
    }
}
