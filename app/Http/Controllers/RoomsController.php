<?php

namespace App\Http\Controllers;

use App\Models\Rooms;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $rooms = Rooms::with('department')->get();
        return response()->json($rooms);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_code' => 'required|string|max:255|unique:rooms,room_code',
            'room_name' => 'nullable|string|max:255',
            'room_type' => 'required|string|in:lecture,laboratory,online,field',
            'status' => 'nullable|string|in:available,occupied,maintenance',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $room = Rooms::create($validated);

        return response()->json([
            'message' => 'Room created successfully.',
            'room' => $room->load('department')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $room = Rooms::with('department')->findOrFail($id);
        return response()->json($room);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $room = Rooms::findOrFail($id);

        $validated = $request->validate([
            'room_code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('rooms')->ignore($room->id)],
            'room_name' => 'nullable|string|max:255',
            'room_type' => 'sometimes|required|string|in:lecture,laboratory,online,field',
            'status' => 'sometimes|nullable|string|in:available,occupied,maintenance',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $room->update($validated);

        return response()->json([
            'message' => 'Room updated successfully.',
            'room' => $room->load('department')
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $room = Rooms::findOrFail($id);
        $room->delete();

        return response()->json([
            'message' => 'Room deleted successfully.'
        ]);
    }

    /**
     * Assign a department to a room.
     */
    public function assign(Request $request, $id)
    {
        $room = Rooms::findOrFail($id);

        $validated = $request->validate([
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $room->update([
            'department_id' => $validated['department_id']
        ]);

        return response()->json([
            'message' => 'Room assignment updated successfully.',
            'room' => $room->load('department')
        ]);
    }
}
