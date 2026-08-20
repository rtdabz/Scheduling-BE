<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\Departments;
use App\Services\Scheduling\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\Request;
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
            ->with(['users' => fn ($query) => $query
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id')
            ])
            ->latest()
            ->get());

        return response()->json($departments);
    }

    /**
     * Store a newly created department in the database.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'department_name' => 'required|string|max:255|unique:departments,department_name',
            'department_code' => 'required|string|max:20|unique:departments,department_code',
            'scheduling_profile' => 'sometimes|in:standard,laboratory_enabled',
            'logo' => 'nullable|string',
        ]);

        $department = Departments::create($validated);
        ApiCache::forgetGroup('departments.index');

        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'users' => fn ($query) => $query
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id'),
        ]), 201);
    }

    /**
     * Display the specified department.
     */
    public function show(Departments $department)
    {
        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'users' => fn ($query) => $query
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id'),
        ]));
    }

    /**
     * Update the specified department in the database.
     */
    public function update(Request $request, Departments $department)
    {
        $validated = $request->validate([
            'department_name' => 'sometimes|required|string|max:255|unique:departments,department_name,' . $department->id,
            'department_code' => 'sometimes|required|string|max:20|unique:departments,department_code,' . $department->id,
            'scheduling_profile' => 'sometimes|in:standard,laboratory_enabled',
            'logo' => 'nullable|string',
        ]);

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
        ApiCache::forgetGroup('departments.index');

        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
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
        ApiCache::forgetGroup('departments.index');
        return response()->json(['message' => 'Department deleted successfully']);
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
     * Permanently delete a soft-deleted department.
     */
    public function forceDelete($id)
    {
        $department = Departments::onlyTrashed()->findOrFail($id);
        $department->forceDelete();

        return redirect()->route('departments.trash')
            ->with('success', 'Department permanently deleted.');
    }

    private function hasLaboratoryCourses(Departments $department): bool
    {
        $curriculum = Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->first();

        return $curriculum?->courses()
            ->with('categories')
            ->get()
            ->contains(fn ($course): bool => SchedulingPolicy::isLaboratoryCourse($course)) ?? false;
    }
}
