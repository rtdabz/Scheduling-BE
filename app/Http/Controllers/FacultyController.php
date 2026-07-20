<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\FacultyLoadService;

class FacultyController extends Controller
{
    public function __construct(private readonly FacultyLoadService $facultyLoad)
    {
    }

    public function index(Request $request)
    {
        $activeTerm = \App\Models\Terms::where('is_active', true)->first();
        $activeTermId = $activeTerm ? $activeTerm->id : null;
        $departmentId = $this->resolveDepartmentId($request);

        return response()->json($this->facultyLoad->get($departmentId, $activeTermId));
    }

    public function store(Request $request)
    {
        $departmentId = $this->resolveDepartmentId($request);
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

        $payload = $request->all();
        if ($departmentId !== null) {
            $payload['department_id'] = $departmentId;
        }

        $faculty = Faculty::create($payload);

        return response()->json($faculty->load('department'), 201);
    }

    public function show(Request $request, Faculty $faculty)
    {
        $departmentId = $this->resolveDepartmentId($request);
        if ($departmentId !== null && (int) $faculty->department_id !== $departmentId) {
            return response()->json(['message' => 'Faculty member not found in your department.'], 404);
        }

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
        $departmentId = $this->resolveDepartmentId($request);
        if ($departmentId !== null && (int) $faculty->department_id !== $departmentId) {
            return response()->json(['message' => 'Faculty member not found in your department.'], 404);
        }

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

        $payload = $request->all();
        if ($departmentId !== null) {
            $payload['department_id'] = $departmentId;
        }

        $faculty->update($payload);

        return response()->json($faculty->load('department'));
    }

    public function destroy(Request $request, Faculty $faculty)
    {
        $departmentId = $this->resolveDepartmentId($request);
        if ($departmentId !== null && (int) $faculty->department_id !== $departmentId) {
            return response()->json(['message' => 'Faculty member not found in your department.'], 404);
        }

        $faculty->delete();
        return response()->json(['message' => 'Faculty deleted successfully']);
    }

    private function resolveDepartmentId(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        if ($user->isVpaa()) {
            $requestedDepartmentId = $request->query('department_id');
            return $requestedDepartmentId !== null && $requestedDepartmentId !== ''
                ? (int) $requestedDepartmentId
                : null;
        }

        return $user->department_id !== null ? (int) $user->department_id : null;
    }
}
