<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Department;
use App\Models\Room;
use App\Models\Faculty;
use App\Models\Subject;
use App\Models\Term;
use App\Models\Section;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    // Get all schedules
    public function index()
    {
        $schedules = Schedule::with([
            'term',
            'section',
            'subject',
            'faculty',
            'room',
            'department'
        ])->latest()->get();

        return response()->json($schedules);
    }

    // Create schedule
    public function store(Request $request)
    {
        $validated = $request->validate([
            'term_id'       => 'required|exists:terms,id',
            'section_id'    => 'required|exists:sections,id',
            'subject_id'    => 'required|exists:subjects,id',
            'faculty_id'    => 'required|exists:faculty,id',
            'room_id'       => 'required|exists:rooms,id',
            'department_id' => 'required|exists:departments,id',
            'day'           => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'required|date_format:H:i|after:start_time',
        ]);

        $schedule = Schedule::create($validated);

        return response()->json($schedule->load([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ]), 201);
    }

    // Get single schedule
    public function show(Schedule $schedule)
    {
        return response()->json($schedule->load([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ]));
    }

    // Update schedule
    public function update(Request $request, Schedule $schedule)
    {
        $validated = $request->validate([
            'term_id'       => 'sometimes|exists:terms,id',
            'section_id'    => 'sometimes|exists:sections,id',
            'subject_id'    => 'sometimes|exists:subjects,id',
            'faculty_id'    => 'sometimes|exists:faculty,id',
            'room_id'       => 'sometimes|exists:rooms,id',
            'department_id' => 'sometimes|exists:departments,id',
            'day'           => 'sometimes|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
            'start_time'    => 'sometimes|date_format:H:i',
            'end_time'      => 'sometimes|date_format:H:i|after:start_time',
            'status'        => 'sometimes|in:pending,approved,conflict',
        ]);

        $schedule->update($validated);

        return response()->json($schedule->load([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ]));
    }

    // Delete schedule
    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return response()->json(['message' => 'Schedule deleted successfully']);
    }

    // Get schedules by term
    public function byTerm($termId)
    {
        $schedules = Schedule::with([
            'section', 'subject', 'faculty', 'room', 'department'
        ])->where('term_id', $termId)->get();

        return response()->json($schedules);
    }

    // Get schedules by section
    public function bySection($sectionId)
    {
        $schedules = Schedule::with([
            'subject', 'faculty', 'room'
        ])->where('section_id', $sectionId)->get();

        return response()->json($schedules);
    }
}