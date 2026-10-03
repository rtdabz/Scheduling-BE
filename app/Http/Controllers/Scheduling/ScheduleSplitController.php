<?php

namespace App\Http\Controllers\Scheduling;

use App\Http\Controllers\Controller;
use App\Models\ScheduleSplit;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleSplitController extends Controller
{
    public function __construct(private readonly ScheduleAuthorizationService $authorization) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ScheduleSplit::query()->with('schedule');

        if ($request->has('schedule_id') && $request->schedule_id) {
            $query->where('schedule_id', $request->schedule_id);
        }

        if ($request->has('split_group_id') && $request->split_group_id) {
            $query->where('split_group_id', $request->split_group_id);
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id'    => 'required|integer|exists:schedules,id|unique:schedule_splits,schedule_id',
            'split_group_id' => 'required|string|max:36',
            'meeting_type'   => 'required|in:lecture,laboratory',
            'meeting_index'  => 'required|integer|min:1',
        ]);
        if (! $this->authorization->scheduleIdsWritable($request, [(int) $validated['schedule_id']])) {
            return $this->programForbidden();
        }

        $split = ScheduleSplit::create($validated);

        return response()->json($split->load('schedule'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ScheduleSplit $scheduleSplit): JsonResponse
    {
        return response()->json($scheduleSplit->load('schedule'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ScheduleSplit $scheduleSplit): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id'    => 'sometimes|required|integer|exists:schedules,id|unique:schedule_splits,schedule_id,' . $scheduleSplit->id,
            'split_group_id' => 'sometimes|required|string|max:36',
            'meeting_type'   => 'sometimes|required|in:lecture,laboratory',
            'meeting_index'  => 'sometimes|required|integer|min:1',
        ]);
        if (! $this->authorization->scheduleIdsWritable($request, [(int) $scheduleSplit->schedule_id, (int) ($validated['schedule_id'] ?? $scheduleSplit->schedule_id)])) {
            return $this->programForbidden();
        }

        $scheduleSplit->update($validated);

        return response()->json($scheduleSplit->load('schedule'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, ScheduleSplit $scheduleSplit): JsonResponse
    {
        if (! $this->authorization->scheduleIdsWritable($request, [(int) $scheduleSplit->schedule_id])) {
            return $this->programForbidden();
        }

        $scheduleSplit->delete();

        return response()->json(['message' => 'Schedule split archived successfully']);
    }

    private function programForbidden(): JsonResponse
    {
        return response()->json(['message' => ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE], 403);
    }
}
