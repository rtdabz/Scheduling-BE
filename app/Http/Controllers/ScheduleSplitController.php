<?php

namespace App\Http\Controllers;

use App\Models\ScheduleSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleSplitController extends Controller
{
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

        $scheduleSplit->update($validated);

        return response()->json($scheduleSplit->load('schedule'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ScheduleSplit $scheduleSplit): JsonResponse
    {
        $scheduleSplit->delete();

        return response()->json(['message' => 'Schedule split deleted successfully']);
    }
}
