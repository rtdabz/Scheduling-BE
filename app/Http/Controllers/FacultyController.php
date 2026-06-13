<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FacultyController extends Controller
{
    public function index()
    {
        $faculties = Faculty::with('department')->get();
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
