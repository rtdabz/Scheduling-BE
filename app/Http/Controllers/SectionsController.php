<?php

namespace App\Http\Controllers;

use App\Models\Sections;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Http\Request;

class SectionsController extends Controller
{
    // Get all sections
    public function index()
    {
        $sections = Sections::with(['department', 'term'])
            ->latest()
            ->get();

        return response()->json($sections);
    }

    // Create section
    public function store(Request $request)
    {
        $validated = $request->validate([
            'section_name'       => 'required|string|max:255',
            'year_level'         => SchedulingPolicy::allowedYearLevelsRule('required'),
            'semester'           => SchedulingPolicy::allowedSemestersRule('required'),
            'department_id'      => 'required|exists:departments,id',
            'term_id'            => 'required|exists:terms,id',
            'status'             => SchedulingPolicy::allowedActiveStatusesRule('sometimes'),
        ]);

        $section = Sections::create($validated);

        return response()->json($section->load(['department', 'term']), 201);
    }

    // Get single section
    public function show(Sections $section)
    {
        return response()->json($section->load(['department', 'term']));
    }

    // Update section
    public function update(Request $request, Sections $section)
    {
        $validated = $request->validate([
            'section_name'       => 'sometimes|string|max:255',
            'year_level'         => SchedulingPolicy::allowedYearLevelsRule('sometimes'),
            'semester'           => SchedulingPolicy::allowedSemestersRule('sometimes'),
            'department_id'      => 'sometimes|exists:departments,id',
            'term_id'            => 'sometimes|exists:terms,id',
            'status'             => SchedulingPolicy::allowedActiveStatusesRule('sometimes'),
        ]);

        $section->update($validated);

        return response()->json($section->load(['department', 'term']));
    }

    // Delete section
    public function destroy(Sections $section)
    {
        $section->delete();
        return response()->json(['message' => 'Section deleted successfully']);
    }

    // Get sections by term
    public function byTerm($termId)
    {
        $sections = Sections::with(['department'])
            ->where('term_id', $termId)
            ->get();

        return response()->json($sections);
    }

    // Get sections by department
    public function byDepartment($departmentId)
    {
        $sections = Sections::with(['term'])
            ->where('department_id', $departmentId)
            ->get();

        return response()->json($sections);
    }
}
