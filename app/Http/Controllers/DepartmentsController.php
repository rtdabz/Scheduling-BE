<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use Illuminate\Http\Request;

class DepartmentsController extends Controller
{
    /**
     * Display a listing of departments.
     */
    public function index()
    {
        $departments = Departments::query()
            ->withCount(['rooms', 'sections', 'faculties'])
            ->with(['users' => fn ($query) => $query
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id')
            ])
            ->latest()
            ->get();

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
        ]);

        $department = Departments::create($validated);

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
            'department_name' => 'required|string|max:255|unique:departments,department_name,' . $department->id,
            'department_code' => 'required|string|max:20|unique:departments,department_code,' . $department->id,
        ]);

        $department->update($validated);

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
}
