<?php

namespace App\Http\Controllers;

use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
    public function store(StoreRoomRequest $request)
    {
        $validated = $request->validated();

        $validated['allow_lecture_usage'] = ($validated['room_type'] ?? null) === 'laboratory'
            && (bool) ($validated['allow_lecture_usage'] ?? false);

        $room = Rooms::create($validated);
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Room created successfully.',
            'room' => $room->load('department'),
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
    public function update(UpdateRoomRequest $request, $id)
    {
        $room = Rooms::findOrFail($id);
        $validated = $request->validated();

        if (($validated['room_type'] ?? $room->room_type) !== 'laboratory') {
            $validated['allow_lecture_usage'] = false;
        }

        $room->update($validated);
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Room updated successfully.',
            'room' => $room->load('department'),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $room = Rooms::findOrFail($id);
        if (Schedule::where('room_id', $room->id)->exists()) {
            return response()->json([
                'message' => 'This room cannot be archived while classes are scheduled in it.',
            ], 422);
        }
        $room->delete();
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Room archived successfully.',
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
            'department_id' => $validated['department_id'],
        ]);
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Room assignment updated successfully.',
            'room' => $room->load('department'),
        ]);
    }
}
