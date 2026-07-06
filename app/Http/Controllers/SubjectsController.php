<?php

namespace App\Http\Controllers;

use App\Models\Subjects;
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
            'subject_category'   => 'required|in:major,gec,gee,pathfit,nstp',
            'room_type_required' => 'nullable|in:lecture,laboratory,field,online',
            'year_level'         => 'required|in:1,2,3,4',
            'semester'           => 'required|in:1st,2nd,summer',
            'department_id'      => 'nullable|exists:departments,id',
            'status'             => 'nullable|in:active,inactive',
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
            'subject_category'   => 'sometimes|required|in:major,gec,gee,pathfit,nstp',
            'room_type_required' => 'nullable|in:lecture,laboratory,field,online',
            'year_level'         => 'sometimes|required|in:1,2,3,4',
            'semester'           => 'sometimes|required|in:1st,2nd,summer',
            'department_id'      => 'nullable|exists:departments,id',
            'status'             => 'nullable|in:active,inactive',
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
