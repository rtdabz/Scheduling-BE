<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FacultyController extends Controller
{
    public function index()
    {
        $activeTerm = \App\Models\Terms::where('is_active', true)->first();
        $activeTermId = $activeTerm ? $activeTerm->id : null;

        $faculties = Faculty::with('department')->get()->map(function ($faculty) use ($activeTermId) {
            if ($activeTermId) {
                $assignedUnits = \DB::table('schedules')
                    ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
                    ->where('schedules.faculty_id', $faculty->id)
                    ->where('schedules.term_id', $activeTermId)
                    ->select('schedules.section_id', 'schedules.subject_id', 'subjects.units')
                    ->distinct()
                    ->get()
                    ->sum('units');

                $assignedSubjects = \DB::table('schedules')
                    ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
                    ->where('schedules.faculty_id', $faculty->id)
                    ->where('schedules.term_id', $activeTermId)
                    ->select('subjects.id', 'subjects.subject_code', 'subjects.subject_name')
                    ->distinct()
                    ->get();

                $assignedSections = \DB::table('schedules')
                    ->join('sections', 'schedules.section_id', '=', 'sections.id')
                    ->where('schedules.faculty_id', $faculty->id)
                    ->where('schedules.term_id', $activeTermId)
                    ->select('sections.id', 'sections.section_name')
                    ->distinct()
                    ->get();
            } else {
                $assignedUnits = 0;
                $assignedSubjects = [];
                $assignedSections = [];
            }

            $faculty->assigned_units = (int) $assignedUnits;
            $faculty->assigned_subjects = $assignedSubjects;
            $faculty->assigned_classes = $assignedSections;
            return $faculty;
        });

        return response()->json($faculties);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'employment_type' => 'required|in:full-time,part-time',
            'max_units' => 'required|integer|min:1',
            'overload_units' => 'nullable|integer|min:0',
            'deload_units' => 'nullable|integer|min:0',
            'probono_units' => 'nullable|integer|min:0',
            'department_id' => 'required|exists:departments,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $faculty = Faculty::create($request->all());

        return response()->json($faculty->load('department'), 201);
    }

    public function show(Faculty $faculty)
    {
        $activeTerm = \App\Models\Terms::where('is_active', true)->first();
        $activeTermId = $activeTerm ? $activeTerm->id : null;

        if ($activeTermId) {
            $assignedUnits = \DB::table('schedules')
                ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
                ->where('schedules.faculty_id', $faculty->id)
                ->where('schedules.term_id', $activeTermId)
                ->select('schedules.section_id', 'schedules.subject_id', 'subjects.units')
                ->distinct()
                ->get()
                ->sum('units');

            $assignedSubjects = \DB::table('schedules')
                ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
                ->where('schedules.faculty_id', $faculty->id)
                ->where('schedules.term_id', $activeTermId)
                ->select('subjects.id', 'subjects.subject_code', 'subjects.subject_name')
                ->distinct()
                ->get();

            $assignedSections = \DB::table('schedules')
                ->join('sections', 'schedules.section_id', '=', 'sections.id')
                ->where('schedules.faculty_id', $faculty->id)
                ->where('schedules.term_id', $activeTermId)
                ->select('sections.id', 'sections.section_name')
                ->distinct()
                ->get();
        } else {
            $assignedUnits = 0;
            $assignedSubjects = [];
            $assignedSections = [];
        }

        $faculty->assigned_units = (int) $assignedUnits;
        $faculty->assigned_subjects = $assignedSubjects;
        $faculty->assigned_classes = $assignedSections;

        return response()->json($faculty->load('department'));
    }

    public function update(Request $request, Faculty $faculty)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'employment_type' => 'sometimes|required|in:full-time,part-time',
            'max_units' => 'sometimes|required|integer|min:1',
            'overload_units' => 'sometimes|nullable|integer|min:0',
            'deload_units' => 'sometimes|nullable|integer|min:0',
            'probono_units' => 'sometimes|nullable|integer|min:0',
            'department_id' => 'sometimes|required|exists:departments,id',
            'status' => 'sometimes|required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $faculty->update($request->all());

        return response()->json($faculty->load('department'));
    }

    public function destroy(Faculty $faculty)
    {
        $faculty->delete();
        return response()->json(['message' => 'Faculty deleted successfully']);
    }
}
