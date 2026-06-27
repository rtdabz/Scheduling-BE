<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Department;
use App\Models\Term;
use App\Models\Schedule;
use Illuminate\Http\Request;

class SectionsController extends Controller
{
    // Get all sections
    public function index()
    {
        $sections = Section::with(['department', 'term'])
            ->latest()
            ->get();

        return response()->json($sections);
    }

    // Create section
    public function store(Request $request)
    {
        $validated = $request->validate([
            'section_name'       => 'required|string|max:255',
            'year_level'         => 'required|in:1,2,3,4',
            'semester'           => 'required|in:1st,2nd,summer',
            'number_of_students' => 'required|integer|min:1',
            'department_id'      => 'required|exists:departments,id',
            'term_id'            => 'required|exists:terms,id',
            'status'             => 'sometimes|in:active,inactive',
        ]);

        $section = Section::create($validated);

        return response()->json($section->load(['department', 'term']), 201);
    }

    // Get single section
    public function show(Section $section)
    {
        return response()->json($section->load(['department', 'term']));
    }

    // Update section
    public function update(Request $request, Section $section)
    {
        $validated = $request->validate([
            'section_name'       => 'sometimes|string|max:255',
            'year_level'         => 'sometimes|in:1,2,3,4',
            'semester'           => 'sometimes|in:1st,2nd,summer',
            'number_of_students' => 'sometimes|integer|min:1',
            'department_id'      => 'sometimes|exists:departments,id',
            'term_id'            => 'sometimes|exists:terms,id',
            'status'             => 'sometimes|in:active,inactive',
        ]);

        $section->update($validated);

        return response()->json($section->load(['department', 'term']));
    }

    // Delete section
    public function destroy(Section $section)
    {
        $section->delete();
        return response()->json(['message' => 'Section deleted successfully']);
    }

    // Get sections by term
    public function byTerm($termId)
    {
        $sections = Section::with(['department'])
            ->where('term_id', $termId)
            ->get();

        return response()->json($sections);
    }

    // Get sections by department
    public function byDepartment($departmentId)
    {
        $sections = Section::with(['term'])
            ->where('department_id', $departmentId)
            ->get();

        return response()->json($sections);
    }
}