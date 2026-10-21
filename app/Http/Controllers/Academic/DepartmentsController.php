<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\Departments;
use App\Support\ApiCache;
use Illuminate\Http\Request;
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
                    ->select('id', 'name', 'role', 'department_id', 'program_id')
                    ->with('program:id,code,name,major'),
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
                ->select('id', 'name', 'role', 'department_id', 'program_id')
                ->with('program:id,code,name,major'),
        ]), 201);
    }

    public function show(Request $request, Departments $department)
    {
        $user = $request->user();
        if (! $user->isVpaa() && (int) $user->department_id !== (int) $department->id) {
            abort(403, 'You can only view your own department.');
        }

        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'programs' => fn ($query) => $query->orderBy('code')->orderBy('major'),
            'users' => fn ($query) => $query
                ->whereIn('role', ['dean', 'secretary', 'program_head'])
                ->select('id', 'name', 'role', 'department_id', 'program_id')
                ->with('program:id,code,name,major'),
        ]));
    }

    public function update(UpdateDepartmentRequest $request, Departments $department)
    {
        $validated = $request->validated();

        $department->update($validated);
        ApiCache::forgetGroups(['departments.index', 'initial.data']);

        return response()->json($department->loadCount(['rooms', 'sections', 'faculties'])->load([
            'programs' => fn ($query) => $query->orderBy('code')->orderBy('major'),
            'users' => fn ($query) => $query
                ->whereIn('role', ['dean', 'secretary', 'program_head'])
                ->select('id', 'name', 'role', 'department_id', 'program_id')
                ->with('program:id,code,name,major'),
        ]));
    }

    public function destroy(Departments $department)
    {
        $blockers = app(\App\Services\AcademicArchiveDependencies::class)->blockers($department);
        if ($blockers !== []) {
            return response()->json([
                'message' => 'This department cannot be archived while these live dependencies remain: '.implode(', ', $blockers).'.',
                'blocking_relations' => $blockers,
            ], 422);
        }

        $department->delete();
        ApiCache::forgetGroups(['departments.index', 'initial.data']);

        return response()->json(['message' => 'Department archived successfully']);
    }

}
