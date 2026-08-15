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
            'profile_picture' => 'nullable|string',
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
            $scheduleRows = \DB::table('schedules')
                ->leftJoin('courses', 'schedules.course_id', '=', 'courses.id')
                ->leftJoin('sections', 'schedules.section_id', '=', 'sections.id')
                ->where('schedules.faculty_id', $faculty->id)
                ->where('schedules.term_id', $activeTermId)
                ->select([
                    'schedules.section_id',
                    'schedules.course_id',
                    'courses.units',
                    'courses.course_code',
                    'courses.course_name',
                    'sections.section_name',
                ])
                ->distinct()
                ->get();

            $assignedUnits = $scheduleRows
                ->unique(fn ($row) => "{$row->section_id}:{$row->course_id}")
                ->sum('units');

            $assignedSubjects = $scheduleRows
                ->unique('course_id')
                ->map(fn ($row) => [
                    'id' => $row->course_id,
                    'course_code' => $row->course_code,
                    'course_name' => $row->course_name,
                    'subject_code' => $row->course_code,
                    'subject_name' => $row->course_name,
                ])
                ->values();

            $assignedSections = $scheduleRows
                ->unique('section_id')
                ->map(fn ($row) => [
                    'id' => $row->section_id,
                    'section_name' => $row->section_name,
                ])
                ->values();
        } else {
            $assignedUnits = 0;
            $assignedSubjects = collect();
            $assignedSections = collect();
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
            'profile_picture' => 'sometimes|nullable|string',
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
