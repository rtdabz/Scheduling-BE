<?php

namespace App\Http\Controllers;

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
    /**
     * Display a listing of departments.
     */
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

    /**
     * Store a newly created department in the database.
     */
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

    /**
     * Display the specified department.
     */
    public function show(Departments $department)
    {
        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'programs' => fn ($query) => $query->orderBy('code')->orderBy('major'),
            'users' => fn ($query) => $query
                ->whereIn('role', ['dean', 'secretary', 'program_head'])
                ->select('id', 'name', 'role', 'department_id'),
        ]));
    }

    /**
     * Update the specified department in the database.
     */
    public function update(UpdateDepartmentRequest $request, Departments $department)
    {
        $validated = $request->validated();

        if (($validated['scheduling_profile'] ?? null) === 'standard' && $this->hasLaboratoryCourses($department)) {
            return response()->json([
                'error_code' => 'department_profile_mismatch',
                'department_profile' => 'standard',
                'message' => 'This department has laboratory courses in its active curriculum and cannot use the standard profile.',
            ], 422);
        }

        if (($validated['scheduling_profile'] ?? null) === 'standard') {
            // Otherwise these stay true forever: SchedulingSettingsController
            // refuses to *enable* them on a standard department and the Settings
            // page greys the toggles out, while CspSolver still reads them
            // (audit finding #37).
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
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id'),
        ]));
    }

    /**
     * Soft delete the specified department.
     */
    public function destroy(Departments $department)
    {
        $department->delete();
        ApiCache::forgetGroups(['departments.index', 'initial.data']);

        return response()->json(['message' => 'Department archived successfully']);
    }

    /**
     * Display soft-deleted departments (trash).
     */
    public function trash()
    {
        $departments = Departments::onlyTrashed()->latest()->paginate(10);

        return view('departments.trash', compact('departments'));
    }

    /**
     * Restore a soft-deleted department.
     */
    public function restore($id)
    {
        $department = Departments::onlyTrashed()->findOrFail($id);
        $department->restore();

        return redirect()->route('departments.trash')
            ->with('success', 'Department restored successfully.');
    }

    /**
     * Whether any curriculum this department runs still teaches a laboratory.
     *
     * Every active curriculum counts, not just the first one the query
     * returns. A department running two curricula would otherwise be allowed
     * onto the standard profile whenever its laboratories happened to live in
     * the curriculum that sorted second, and every section on that curriculum
     * would then fail preflight with department_profile_mismatch.
     */
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
