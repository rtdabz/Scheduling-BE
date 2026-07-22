<?php

namespace App\Http\Controllers;

use App\Models\Rooms;
use App\Services\Scheduling\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class RoomsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $rooms = Cache::remember(ApiCache::key('rooms.index'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Rooms::with('department')->get());
        return response()->json($rooms);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_code' => 'required|string|max:255|unique:rooms,room_code',
            'building' => 'nullable|string|in:NEE Building,Building 1,Building 2,Building 3,Building 4,Building 5,Building 6',
            'room_type' => SchedulingPolicy::allowedRoomTypesRule('required|string'),
            'status' => SchedulingPolicy::allowedRoomStatusesRule('nullable|string'),
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $room = Rooms::create($validated);
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
        ]);

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
            'building' => 'nullable|string|in:NEE Building,Building 1,Building 2,Building 3,Building 4,Building 5,Building 6',
            'room_type' => SchedulingPolicy::allowedRoomTypesRule('sometimes|required|string'),
            'status' => SchedulingPolicy::allowedRoomStatusesRule('sometimes|nullable|string'),
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $room->update($validated);
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
        ]);

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
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
        ]);

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
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
        ]);

        return response()->json([
            'message' => 'Room assignment updated successfully.',
            'room' => $room->load('department')
        ]);
    }
}
