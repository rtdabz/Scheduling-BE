<?php

namespace App\Http\Controllers;

use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RoomsController extends Controller
{
    public function __construct(
        private readonly ScheduleAuthorizationService $authorization,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // A Program Head does not see rooms homed to a sibling program.
        $programId = $this->authorization->programScope($request);
        $rooms = Cache::remember(
            ApiCache::key('rooms.index', ['program_id' => $programId]),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => $this->authorization->scopeRoomsToProgram(Rooms::with('department'), $request)->get(),
        );

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
    public function show(Request $request, $id)
    {
        $room = $this->authorization->scopeRoomsToProgram(Rooms::with('department'), $request)->findOrFail($id);

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

        // Closing a room must not leave this semester's classes booked in it;
        // generation and validation skip unavailable rooms, so they would
        // never be flagged. Past semesters do not block it.
        $closing = array_key_exists('status', $validated)
            && $validated['status'] !== 'available'
            && $room->status === 'available';
        if ($closing) {
            $activeClasses = Schedule::query()
                ->where('room_id', $room->id)
                ->whereHas('academicSemester', fn ($query) => $query->where('is_active', true))
                ->count();
            if ($activeClasses > 0) {
                return response()->json([
                    'message' => "This room cannot be marked unavailable while {$activeClasses} class meeting(s) this semester are scheduled in it. Move them to another room first.",
                    'errors' => ['status' => ['This room still has classes scheduled this semester.']],
                ], 422);
            }
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
