<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Support\Facades\Cache;

class DepartmentsController extends Controller
{
    public function index()
    {
        $departments = Cache::remember(ApiCache::key('departments.index'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Departments::query()
            ->withCount(['rooms', 'sections', 'faculties'])
            ->with([
                'programs' => fn ($query) => $query->orderBy('code')->orderBy('major'),
                'users' => fn ($query) => $query
                    ->whereIn('role', ['dean', 'secretary', 'program_head'])
                    ->select('id', 'name', 'role', 'department_id'),
            ])
            ->latest()
            ->get());

        return response()->json($departments);
    }

    public function store(StoreDepartmentRequest $request)
    {
        $validated = $request->validated();

        $department = Departments::create($validated);
        ApiCache::forgetGroups(['departments.index', 'initial.data']);

        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'programs' => fn ($query) => $query->orderBy('code')->orderBy('major'),
            'users' => fn ($query) => $query
                ->whereIn('role', ['dean', 'secretary', 'program_head'])
                ->select('id', 'name', 'role', 'department_id'),
        ]), 201);
    }

    public function show(Departments $department)
    {
        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'programs' => fn ($query) => $query->orderBy('code')->orderBy('major'),
            'users' => fn ($query) => $query
                ->whereIn('role', ['dean', 'secretary', 'program_head'])
                ->select('id', 'name', 'role', 'department_id'),
        ]));
    }

    public function update(UpdateDepartmentRequest $request, Departments $department)
    {
        $validated = $request->validated();

        if (($validated['scheduling_profile'] ?? null) === 'standard' && $this->hasLaboratoryCourses($department)) {
            return response()->json([
                'error_code' => 'department_profile_mismatch',
                'department_profile' => 'standard',
                'message' => 'This department still has laboratory courses in its active curriculum, so specialized rooms must stay on.',
            ], 422);
        }

        if (($validated['scheduling_profile'] ?? null) === 'standard') {
            $validated += [
                'lecture_lab_schedule_override_enabled' => false,
                'custom_lab_duration_override_enabled' => false,
                'custom_lab_duration_6_hours_enabled' => false,
                'custom_lab_duration_5_hours_enabled' => false,
                'custom_lab_duration_other_enabled' => false,
            ];
        }

        $department->update($validated);
        ApiCache::forgetGroups(['departments.index', 'initial.data']);

        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'programs' => fn ($query) => $query->orderBy('code')->orderBy('major'),
            'users' => fn ($query) => $query
                ->whereIn('role', ['dean', 'secretary', 'program_head'])
                ->select('id', 'name', 'role', 'department_id'),
        ]));
    }

    public function destroy(Departments $department)
    {
        if ($department->programs()->exists() || $department->sections()->exists()
            || $department->faculties()->exists() || $department->users()->exists()
            || $department->rooms()->exists()) {
            return response()->json([
                'message' => 'This department cannot be archived while programs, sections, faculty, users, or rooms are assigned to it.',
            ], 422);
        }

        $department->delete();
        ApiCache::forgetGroups(['departments.index', 'initial.data']);

        return response()->json(['message' => 'Department archived successfully']);
    }

    private function hasLaboratoryCourses(Departments $department): bool
    {
        $activeCurriculumIds = Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->pluck('id');

        if ($activeCurriculumIds->isEmpty()) {
            return false;
        }

        return Course::query()
            ->whereHas('curriculum', fn ($scope) => $scope->whereIn('curriculum.id', $activeCurriculumIds))
            ->get(['id', 'lab_hours', 'room_type_required'])
            ->contains(fn (Course $course): bool => SchedulingPolicy::isLaboratoryCourse($course));
    }
}
