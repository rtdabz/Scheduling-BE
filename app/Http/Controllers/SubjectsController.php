<?php

namespace App\Http\Controllers;

use App\Models\Subjects;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubjectsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Subjects::with('department')->where('status', 'active');

        if ($request->has('department_id')) {
            $deptId = $request->department_id;
            $query->where(function ($q) use ($deptId) {
                $q->where('department_id', $deptId)
                ->orWhere('subject_category', 'gee')
                ->orWhere('subject_category', 'gec');
            });
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject_code'       => 'required|string|unique:subjects,subject_code|max:255',
            'subject_name'       => 'required|string|max:255',
            'lecture_hours'      => 'nullable|integer|min:0',
            'lab_hours'          => 'nullable|integer|min:0',
            'units'              => 'required|integer|min:0',
            'subject_category'   => SchedulingPolicy::allowedSubjectCategoriesRule('required'),
            'room_type_required' => SchedulingPolicy::allowedRoomTypesRule('nullable'),
            'year_level'         => SchedulingPolicy::allowedYearLevelsRule('required'),
            'semester'           => SchedulingPolicy::allowedSemestersRule('required'),
            'department_id'      => 'nullable|exists:departments,id',
            'status'             => SchedulingPolicy::allowedActiveStatusesRule('nullable'),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $subject = Subjects::create($request->all());

        return response()->json($subject->load('department'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Subjects $subject)
    {
        return response()->json($subject->load('department'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Subjects $subject)
    {
        $validator = Validator::make($request->all(), [
            'subject_code'       => 'sometimes|required|string|max:255|unique:subjects,subject_code,' . $subject->id,
            'subject_name'       => 'sometimes|required|string|max:255',
            'lecture_hours'      => 'nullable|integer|min:0',
            'lab_hours'          => 'nullable|integer|min:0',
            'units'              => 'sometimes|required|integer|min:0',
            'subject_category'   => SchedulingPolicy::allowedSubjectCategoriesRule('sometimes|required'),
            'room_type_required' => SchedulingPolicy::allowedRoomTypesRule('nullable'),
            'year_level'         => SchedulingPolicy::allowedYearLevelsRule('sometimes|required'),
            'semester'           => SchedulingPolicy::allowedSemestersRule('sometimes|required'),
            'department_id'      => 'nullable|exists:departments,id',
            'status'             => SchedulingPolicy::allowedActiveStatusesRule('nullable'),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $subject->update($request->all());

        return response()->json($subject->load('department'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subjects $subject)
    {
        $subject->delete();
        return response()->json(['message' => 'Subject deleted successfully']);
    }
}
