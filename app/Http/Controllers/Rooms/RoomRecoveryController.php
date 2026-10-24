<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\RoomRecoveryPlan;
use App\Models\Rooms;
use App\Models\Semester;
use App\Services\RoomRecoveryService;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class RoomRecoveryController extends Controller
{
    public function __construct(private readonly RoomRecoveryService $recovery) {}

    public function index(Request $request)
    {
        $input = $request->validate(['semester_id' => 'nullable|integer|exists:semesters,id', 'include_schedules' => 'sometimes|boolean']);
        $semesterId = (int) ($input['semester_id'] ?? Semester::where('is_active', true)->value('id'));
        $auth = app(ScheduleAuthorizationService::class);
        $rooms = $auth->scopeRoomsToProgram(Rooms::query()->whereNotIn('room_type', Rooms::SHARED_ROOM_TYPES), $request);
        $departmentId = $auth->departmentScope($request);
        if ($departmentId !== null) {
            app(RoomAccessPolicy::class)->scopeReachableRooms($rooms, $departmentId, $semesterId);
        }
        $roomData = $rooms->orderBy('room_code')->get(['id', 'room_code', 'room_type', 'status', 'created_at']);
        $models = $semesterId > 0
            ? $this->recovery->readable($request)->where('schedules.semester_id', $semesterId)->where('mode', 'on-site')
                ->with(['course', 'section', 'room'])->orderBy('schedules.id')->get()
            : new Collection;
        $problems = $this->recovery->roomProblems($models);
        $transfers = $this->recovery->transfers($request, $semesterId);
        $pending = [];
        foreach ($transfers as $transfer) {
            if (in_array($transfer['status'], ['pending_dean', 'pending_vpaa'], true)) {
                foreach ($transfer['schedule_ids'] as $id) {
                    $pending[$id] = ['id' => $transfer['id'], 'status' => $transfer['status']];
                }
            }
        }
        $rows = $request->boolean('include_schedules')
            ? $models->map(function ($row) use ($problems, $pending) {
                $needsAttention = $problems[$row->id];
                $reason = ! $needsAttention ? null : match (true) {
                    $row->room === null => 'Room required',
                    $row->room->status !== 'available' => 'Room unavailable',
                    default => 'Room access or type needs review',
                };

                return [
                    'id' => $row->id, 'course' => $row->course?->course_code, 'section' => $row->section?->section_name,
                    'room' => $row->room?->room_code ?? 'Room TBA', 'day' => $row->day, 'start_time' => $row->start_time,
                    'end_time' => $row->end_time, 'meeting_type' => $row->meeting_type, 'status' => $row->status,
                    'department_id' => $row->department_id, 'needs_attention' => $needsAttention,
                    'revision_required' => $this->recovery->requiresRevision($row),
                    'pending_transfer_id' => $pending[$row->id]['id'] ?? null,
                    'pending_transfer_status' => $pending[$row->id]['status'] ?? null,
                    'room_status' => $row->room?->status, 'attention_reason' => $reason,
                    'required_room_type' => $row->course
                        ? SchedulingPolicy::effectiveRoomType($row->course, $row->department_id, $row->meeting_type)
                        : null,
                ];
            })->all() : [];

        return response()->json(['cases' => $this->recovery->cases($request), 'semester_id' => $semesterId, 'transfers' => $transfers,
            'pending_count' => count(array_filter($problems)),
            'semesters' => Semester::orderByDesc('id')->get(['id', 'academic_year', 'semester', 'is_active']),
            'rooms' => $roomData, 'new_rooms' => $roomData->filter(fn ($room) => $room->status === 'available' && $room->created_at?->gte(now()->subDays(14)))->values(),
            'schedules' => $rows]);
    }

    public function close(Request $request, Rooms $room)
    {
        $input = $request->validate(['reason' => 'required|string|max:1000']);
        abort_if(trim($input['reason']) === '', 422, 'A closure reason is required.');
        $this->recovery->close($room, trim($input['reason']), $request->user()->id);

        return response()->json(['message' => 'Room marked unavailable. Affected departments can review recovery from their Dashboard.']);
    }

    public function preview(Request $request)
    {
        $input = $request->validate([
            'schedule_ids' => 'required|array|min:1|max:30', 'schedule_ids.*' => 'required|integer|distinct|min:1',
            'destination_room_ids' => 'sometimes|array|max:50', 'destination_room_ids.*' => 'integer|distinct|min:1',
            'allow_time_changes' => 'sometimes|boolean',
        ]);

        return response()->json($this->recovery->preview($request, $input));
    }

    public function submit(Request $request, RoomRecoveryPlan $plan)
    {
        $input = $request->validate(['group_keys' => 'required|array|min:1|max:30', 'group_keys.*' => 'required|string|distinct']);

        return response()->json($this->recovery->submit($request, $plan, $input['group_keys']));
    }

    public function transfers(Request $request)
    {
        return response()->json(['transfers' => $this->recovery->transfers($request)]);
    }

    public function approve(Request $request, RoomRecoveryPlan $plan)
    {
        return response()->json($this->recovery->approve($request, $plan));
    }

    public function reject(Request $request, RoomRecoveryPlan $plan)
    {
        $input = $request->validate(['reason' => 'required|string|max:1000']);
        abort_if(trim($input['reason']) === '', 422, 'A rejection reason is required.');

        return response()->json($this->recovery->reject($request, $plan, trim($input['reason'])));
    }
}
