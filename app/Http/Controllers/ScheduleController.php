<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    protected RuleEngine $ruleEngine;

    public function __construct(RuleEngine $ruleEngine)
    {
        $this->ruleEngine = $ruleEngine;
    }

    // Get all schedules
    public function index()
    {
        $schedules = Schedule::with([
            'term', 'section', 'subject', 'faculty', 'room', 'department'
        ])->latest()->get();

        return response()->json($schedules);
    }

    // Create schedule
    public function store(Request $request)
    {
        $validated = $request->validate([
            'term_id'           => 'required|exists:terms,id',
            'section_id'        => 'required|exists:sections,id',
            'subject_id'        => 'required|exists:subjects,id',
            'faculty_id'        => 'nullable|exists:faculties,id',
            'room_id'           => 'required|exists:rooms,id',
            'department_id'     => 'required|exists:departments,id',
            'day'               => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time'        => 'required|date_format:H:i',
            'end_time'          => 'required|date_format:H:i|after:start_time',
            'mode'              => 'sometimes|in:on-site,online,field',
            'is_hybrid'         => 'sometimes|boolean',
            'preferred_pattern' => 'nullable|in:MW,TTh',
            'status'            => 'sometimes|in:draft,submitted,approved_by_dean,rejected_by_dean,approved,faculty_assignment,finalized,rejected',
        ]);

        // ── Rule Engine validation BEFORE saving ──
        $violations = $this->ruleEngine->validate($validated);

        if (!empty($violations)) {
            return response()->json([
                'message'    => 'Schedule conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

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
            'term_id'           => 'sometimes|exists:terms,id',
            'section_id'        => 'sometimes|exists:sections,id',
            'subject_id'        => 'sometimes|exists:subjects,id',
            'faculty_id'        => 'nullable|exists:faculties,id',
            'room_id'           => 'sometimes|exists:rooms,id',
            'department_id'     => 'sometimes|exists:departments,id',
            'day'               => 'sometimes|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time'        => 'sometimes|date_format:H:i',
            'end_time'          => 'sometimes|date_format:H:i|after:start_time',
            'mode'              => 'sometimes|in:on-site,online,field',
            'is_hybrid'         => 'sometimes|boolean',
            'preferred_pattern' => 'nullable|in:MW,TTh',
            'status'            => 'sometimes|in:draft,submitted,approved_by_dean,rejected_by_dean,approved,faculty_assignment,finalized,rejected',
            'rejection_reason'  => 'nullable|string',
            'reviewed_by_dean'  => 'nullable|exists:users,id',
            'reviewed_at_dean'  => 'nullable',
            'approved_by_vpaa'  => 'nullable|exists:users,id',
            'approved_at_vpaa'  => 'nullable',
        ]);

        // ── Rule Engine validation — merge existing + incoming so partial
        //    updates (e.g. just changing room_id) still validate the FULL slot ──
        $attempt = array_merge($schedule->toArray(), $validated);
        $attempt['ignore_schedule_id'] = $schedule->id;

        // Only run conflict checks if scheduling-relevant fields are present
        $violations = $this->ruleEngine->validate($attempt);

        if (!empty($violations)) {
            return response()->json([
                'message'    => 'Schedule conflicts with existing entries.',
                'violations' => $violations,
            ], 422);
        }

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