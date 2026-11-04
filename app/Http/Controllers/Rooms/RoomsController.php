<?php

namespace App\Http\Controllers\Rooms;

use App\Exceptions\ScheduleConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Models\RoomRequest;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Semester;
use App\Services\RoomStructuralUpdate;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
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
            ApiCache::compositeKey('rooms.index', ['departments.index'], ['program_id' => $programId]),
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

        try {
            app(RoomStructuralUpdate::class)->apply($room, $validated);
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }
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
        $blocked = $this->archiveRooms([$room->id], function ($rooms, $booked, $requested) {
            if ($booked->isNotEmpty()) {
                return 'This room cannot be archived while classes are scheduled in it.';
            }
            if ($requested->isNotEmpty()) {
                return 'This room cannot be archived while it has pending room requests or approved room grants. '
                    .'Reject, cancel, or revoke them first.';
            }

            return null;
        });
        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 422);
        }
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
        $to = Rooms::normalizeBuilding($validated['name']);

        $rooms = Rooms::inBuilding($validated['building']);
        if ($rooms->isEmpty()) {
            return response()->json(['message' => 'This building has no rooms.'], 404);
        }

        $taken = Rooms::inBuilding((string) $to)->whereNotIn('id', $rooms->pluck('id'))->isNotEmpty();
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

        $rooms = Rooms::inBuilding($validated['building']);
        if ($rooms->isEmpty()) {
            return response()->json(['message' => 'This building has no rooms.'], 404);
        }

        $blocked = $this->archiveRooms($rooms->pluck('id')->all(), function ($locked, $booked, $requested) {
            $codes = fn ($ids) => $locked->whereIn('id', $ids)->pluck('room_code')->sort()->implode(', ');
            if ($booked->isNotEmpty()) {
                return "This building cannot be archived while classes are scheduled in its rooms: {$codes($booked)}.";
            }
            if ($requested->isNotEmpty()) {
                return "This building cannot be archived while its rooms have pending room requests or approved room grants: {$codes($requested)}. "
                    .'Reject, cancel, or revoke them first.';
            }

            return null;
        });
        if ($blocked !== null) {
            return response()->json(['message' => $blocked], 422);
        }
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

    /**
     * Archive rooms only when no class or unresolved room request depends on them.
     * Runs under the same scheduling lock and room-row locks as room-request
     * submission and approval, so neither can slip in between the check and the archive.
     *
     * @param  list<int>  $roomIds
     * @param  callable(\Illuminate\Support\Collection, \Illuminate\Support\Collection, \Illuminate\Support\Collection): ?string  $blocker
     */
    private function archiveRooms(array $roomIds, callable $blocker): ?string
    {
        return app(SchedulingScopeLock::class)->execute(
            Semester::pluck('id')->map('intval')->all(),
            fn () => DB::transaction(function () use ($roomIds, $blocker): ?string {
                $rooms = Rooms::query()->whereIn('id', $roomIds)->lockForUpdate()->get();
                $booked = Schedule::query()->whereIn('room_id', $rooms->pluck('id'))->distinct()->pluck('room_id');
                $requested = RoomRequest::query()->whereIn('room_id', $rooms->pluck('id'))
                    ->unresolvedOperational()->distinct()->pluck('room_id');

                $message = $blocker($rooms, $booked, $requested);
                if ($message === null) {
                    $rooms->each->delete();
                }

                return $message;
            }),
        );
    }

    public function assign(Request $request, $id)
    {
        $room = Rooms::findOrFail($id);

        $validated = $request->validate([
            'department_id' => 'nullable|exists:departments,id,deleted_at,NULL',
        ]);

        try {
            app(RoomStructuralUpdate::class)->apply($room, ['department_id' => $validated['department_id']]);
        } catch (ScheduleConflictException $exception) {
            return response()->json($exception->payload(), 422);
        }
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
