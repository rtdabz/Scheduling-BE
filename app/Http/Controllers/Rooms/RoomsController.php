<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RoomsController extends Controller
{
    public function __construct(
        private readonly ScheduleAuthorizationService $authorization,
    ) {}

    public function index(Request $request)
    {
        $programId = $this->authorization->programScope($request);
        $rooms = Cache::remember(
            ApiCache::key('rooms.index', ['program_id' => $programId]),
            ApiCache::LOOKUP_TTL_SECONDS,
            fn () => $this->authorization->scopeRoomsToProgram(Rooms::with('department'), $request)->get(),
        );

        return response()->json($rooms);
    }

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

    public function show(Request $request, $id)
    {
        $room = $this->authorization->scopeRoomsToProgram(Rooms::with('department'), $request)->findOrFail($id);

        return response()->json($room);
    }

    public function update(UpdateRoomRequest $request, $id)
    {
        $room = Rooms::findOrFail($id);
        $validated = $request->validated();

        if (($validated['room_type'] ?? $room->room_type) !== 'laboratory') {
            $validated['allow_lecture_usage'] = false;
        }

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

    public function renameBuilding(Request $request)
    {
        $validated = $request->validate([
            'building' => 'required|string|max:100',
            'name' => 'required|string|max:100',
        ]);
        $from = trim($validated['building']);
        $to = preg_replace('/\s+/', ' ', trim($validated['name']));

        $rooms = $this->roomsInBuilding($from);
        if ($rooms->isEmpty()) {
            return response()->json(['message' => 'This building has no rooms.'], 404);
        }

        $taken = Rooms::query()
            ->whereNotIn('id', $rooms->pluck('id'))
            ->whereRaw('LOWER(TRIM(building)) = ?', [mb_strtolower($to)])
            ->exists();
        if ($taken) {
            return response()->json([
                'message' => "A building named \"{$to}\" already exists.",
                'errors' => ['name' => ['A building with this name already exists.']],
            ], 422);
        }

        Rooms::query()->whereIn('id', $rooms->pluck('id'))->update(['building' => $to]);
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Building renamed successfully.',
            'building' => $to,
            'rooms' => Rooms::with('department')->whereIn('id', $rooms->pluck('id'))->get(),
        ]);
    }

    public function archiveBuilding(Request $request)
    {
        $validated = $request->validate([
            'building' => 'required|string|max:100',
        ]);

        $rooms = $this->roomsInBuilding(trim($validated['building']));
        if ($rooms->isEmpty()) {
            return response()->json(['message' => 'This building has no rooms.'], 404);
        }

        $booked = Schedule::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->distinct()
            ->pluck('room_id');
        if ($booked->isNotEmpty()) {
            $codes = $rooms->whereIn('id', $booked)->pluck('room_code')->sort()->implode(', ');

            return response()->json([
                'message' => "This building cannot be archived while classes are scheduled in its rooms: {$codes}.",
            ], 422);
        }

        DB::transaction(fn () => $rooms->each->delete());
        ApiCache::forgetGroups([
            'rooms.index',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Building archived successfully.',
            'archived_room_ids' => $rooms->pluck('id'),
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Rooms> */
    private function roomsInBuilding(string $building)
    {
        return Rooms::query()
            ->whereRaw('TRIM(building) = ?', [$building])
            ->get();
    }

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
