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

class InitialDataController extends Controller
{
    public function __construct(
        private readonly FacultyLoadService $facultyLoad,
        private readonly DepartmentResourceSlotLimitService $resourceLimits,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $departmentId = $user->isVpaa() || $user->department_id === null
            ? null
            : (int) $user->department_id;
        $activeTerm = Terms::query()->where('is_active', true)->first();
        $activeTermId = $activeTerm?->id;
        $hasCourseCategories = Schema::hasTable('course_categories')
            && Schema::hasTable('course_category_mapping');

        $rooms = Rooms::query()
            ->with('department')
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope
                    ->whereNull('department_id')
                    ->orWhere('department_id', $departmentId),
            ))
            ->get();

        $activeCurriculaQuery = Curriculum::query()->where('status', 'active');
        if ($departmentId !== null) {
            $activeCurriculaQuery->where('department_id', $departmentId);
        }
        $activeCurricula = $activeCurriculaQuery->get();

        if ($activeCurricula->isNotEmpty()) {
            $semOrder = ['1st' => 1, '2nd' => 2, 'summer' => 3];
            $courseRelations = ['department', 'teachingAssignment.department'];
            if ($hasCourseCategories) {
                $courseRelations[] = 'categories';
            }

            $courses = Course::with($courseRelations)
                ->whereHas('curricula', function ($q) use ($activeCurricula) {
                    $q->whereIn('curricula.id', $activeCurricula->pluck('id'));
                })
                // Only include courses that belong to this department or are shared minors (null dept)
                ->when($departmentId !== null, function ($q) use ($departmentId) {
                    $q->where(function ($scope) use ($departmentId) {
                        $scope->whereNull('department_id')
                              ->orWhere('department_id', $departmentId);
                    });
                })
                ->get();

            $pivotData = \DB::table('curriculum_course')
                ->whereIn('curriculum_id', $activeCurricula->pluck('id'))
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
            $courses = collect();
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
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeTermId !== null, fn (Builder $query) => $query->where('term_id', $activeTermId))
            ->latest()
            ->get();

        $departments = Departments::query()
            ->withCount(['rooms', 'sections', 'faculties'])
            ->with(['users' => fn ($query) => $query
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id')])
            ->latest()
            ->get();

        return response()->json([
            'active_term' => $activeTerm,
            'rooms' => $rooms,
            'courses' => $courses,
            'subjects' => $courses, // Backwards compatible alias
            // Auto-Assign needs to offer department and external instructors in the same workflow.
            'faculties' => $this->facultyLoad->get(null, $activeTermId),
            'sections' => $sections,
            'schedules' => $schedules,
            'departments' => $departments,
            'field_course_assignment_enabled' => SchedulingPolicy::fieldCourseSettingEnabled(),
            'field_course_codes' => array_keys(SchedulingPolicy::fieldCourseCodeMap()),
            'resource_slot_limits' => $departmentId !== null
                ? $this->resourceLimits->forDepartment($departmentId)
                : null,
            'users' => User::query()
                ->with('department')
                ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
                ->latest()
                ->get(),
        ]);
    }
}
