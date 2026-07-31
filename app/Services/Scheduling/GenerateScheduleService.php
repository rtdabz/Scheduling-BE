<?php

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleRecommendation;
use App\Models\Sections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateScheduleService
{
    /**
     * Course categories that must always meet in 'field' mode
     * (PATHFIT, NSTP), regardless of what room_type_required says.
     */
    private const FIELD_CATEGORIES = ['pathfit', 'nstp'];

    public function __construct(
        private readonly CSPSolver $solver,
        private readonly RuleEngine $ruleEngine,
    ) {}

    /**
     * Generate up to $maxSolutions ranked, conflict-free schedule options
     * for the given section, and persist each as a row in
     * schedule_recommendations so the choice is auditable later.
     *
     * @param  int    $sectionId
     * @param  int    $requestedByUserId
     * @param  int    $maxSolutions
     * @return array
     */
    public function generate(
        int $sectionId,
        int $requestedByUserId,
        int $maxSolutions = 2
    ): array {
        $section = Sections::with('term')->findOrFail($sectionId);

        $courseIds = $this->resolveCourseIdsFromActiveCurriculum($section);

        $solutions = $this->solver->solveRanked(
            sectionId: $sectionId,
            subjectIds: $courseIds,
            maxSolutions: $maxSolutions,
        );

        if (empty($solutions)) {
            return [
                'batch_id'              => null,
                'solutions'             => [],
                'solution_count'        => 0,
                'iterations'            => $this->solver->iterationsUsed(),
                'search_limit_reached'  => $this->solver->searchLimitReached(),
            ];
        }

        $courseCategoryMap = Course::whereIn('id', $courseIds)
            ->pluck('course_category', 'id');

        // Ties every recommendation row from this single generate() call
        // together, so accept() can find/reject siblings later.
        $batchId = (string) Str::uuid();

        $storedSolutions = [];

        foreach ($solutions as $rank => $solution) {
            $meetings = $this->applyMode($solution['schedules'], $courseCategoryMap);

            $recommendation = ScheduleRecommendation::create([
                'batch_id'              => $batchId,
                'term_id'               => $section->term_id,
                'section_id'            => $sectionId,
                'department_id'         => $section->department_id,
                'requested_by'          => $requestedByUserId,
                'rank'                  => $rank + 1,
                'score'                 => $solution['score'] ?? 0,
                'status'                => 'pending',
                'input_payload'         => json_encode(['course_ids' => $courseIds]),
                'recommended_schedules' => json_encode($meetings),
            ]);

            $storedSolutions[] = [
                'recommendation_id' => $recommendation->id,
                'rank'              => $recommendation->rank,
                'score'             => $recommendation->score,
                'schedules'         => $meetings,
            ];
        }

        return [
            'batch_id'             => $batchId,
            'solutions'            => $storedSolutions,
            'solution_count'       => count($storedSolutions),
            'iterations'           => $this->solver->iterationsUsed(),
            'search_limit_reached' => $this->solver->searchLimitReached(),
        ];
    }

    /**
     * Find the department's ACTIVE curriculum, then pull every course
     * linked to it for this section's year_level and the active term's
     * semester.
     *
     * @param  Sections  $section
     * @return array<int>  course IDs
     */
    private function resolveCourseIdsFromActiveCurriculum(Sections $section): array
    {
        $curriculum = Curriculum::where('department_id', $section->department_id)
            ->where('status', 'active')
            ->first();

        if (!$curriculum) {
            abort(response()->json([
                'message' => 'No active curriculum found for this department. '
                           . 'Activate a curriculum before generating a schedule.',
            ], 422));
        }

        $semester = $section->term?->semester;

        if (!$semester) {
            abort(response()->json([
                'message' => 'This section\'s term has no semester set — cannot resolve curriculum courses.',
            ], 422));
        }

        $courseIds = DB::table('curriculum_course')
            ->where('curriculum_id', $curriculum->id)
            ->where('year_level', $section->year_level)
            ->where('semester', $semester)
            ->pluck('course_id')
            ->toArray();

        if (empty($courseIds)) {
            abort(response()->json([
                'message' => "The active curriculum ({$curriculum->name}) has no courses defined "
                           . "for Year {$section->year_level}, {$semester} semester.",
            ], 422));
        }

        return $courseIds;
    }

    /**
     * Accept one recommendation: persist its meetings as real Schedule
     * rows (status: draft), mark it accepted, and mark its sibling
     * options (same batch_id) as rejected since only one gets chosen.
     *
     * @param  int    $recommendationId
     * @param  int    $userId
     * @param  array  $overrides
     * @return array{message: string, schedules: array}
     */
    public function accept(int $recommendationId, int $userId, array $overrides = []): array
    {
        $recommendation = ScheduleRecommendation::findOrFail($recommendationId);

        if ($recommendation->status !== 'pending') {
            abort(response()->json([
                'message' => 'This recommendation has already been ' . $recommendation->status . '.',
            ], 422));
        }

        $meetings = json_decode($recommendation->recommended_schedules, true);
        $meetings = $this->applyOverrides($meetings, $overrides);

        $violations = $this->validateMeetings(
            $meetings,
            $recommendation->term_id,
            $recommendation->section_id
        );

        if (!empty($violations)) {
            return [
                'message'    => 'The refined schedule conflicts with existing entries. Nothing was saved.',
                'violations' => $violations,
                'schedules'  => [],
            ];
        }

        // Fix #4: Prevent duplicate schedule rows for the same course in the same term.
        $existingCourseIds = Schedule::where('section_id', $recommendation->section_id)
            ->where('term_id', $recommendation->term_id)
            ->pluck('course_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $duplicates = [];
        foreach ($meetings as $meeting) {
            if (in_array((int) $meeting['course_id'], $existingCourseIds, true)) {
                $course = Course::find($meeting['course_id']);
                $duplicates[] = [
                    'rule'      => 'duplicate_section_subject',
                    'message'   => sprintf(
                        'Section already has a schedule for %s (%s) in this term. '
                        . 'Cannot accept this recommendation.',
                        $course?->course_name ?? 'course ' . $meeting['course_id'],
                        $course?->course_code ?? '',
                    ),
                    'course_id' => (int) $meeting['course_id'],
                ];
            }
        }

        if (!empty($duplicates)) {
            return [
                'message'    => 'One or more courses in this recommendation are already scheduled for this section.',
                'violations' => $duplicates,
                'schedules'  => [],
            ];
        }

        return DB::transaction(function () use ($recommendation, $userId, $meetings) {
            $created = [];
            foreach ($meetings as $meeting) {
                $created[] = Schedule::create([
                    'term_id'       => $recommendation->term_id,
                    'section_id'    => $recommendation->section_id,
                    'department_id' => $recommendation->department_id,
                    'course_id'     => $meeting['course_id'],
                    'faculty_id'    => null, // assigned later, Phase 2
                    'room_id'       => $meeting['room_id'],
                    'day'           => $meeting['day'],
                    'start_time'    => $meeting['start_time'],
                    'end_time'      => $meeting['end_time'],
                    'mode'          => $meeting['mode'],
                    'status'        => 'draft',
                ]);
            }

            $recommendation->update([
                'status'                => 'accepted',
                'accepted_by'           => $userId,
                'accepted_at'           => now(),
                'recommended_schedules' => json_encode($meetings),
            ]);

            // Reject the other options generated in the same batch
            ScheduleRecommendation::where('batch_id', $recommendation->batch_id)
                ->where('id', '!=', $recommendation->id)
                ->where('status', 'pending')
                ->update([
                    'status'           => 'rejected',
                    'rejected_by'      => $userId,
                    'rejected_at'      => now(),
                    'rejection_reason' => 'Another option from the same batch was accepted.',
                ]);

            return [
                'message'   => 'Schedule option accepted and saved as draft.',
                'schedules' => $created,
            ];
        });
    }

    /**
     * Apply Secretary-provided overrides onto the solver's proposed
     * meetings before validation/save.
     */
    private function applyOverrides(array $meetings, array $overrides): array
    {
        foreach ($overrides as $index => $fields) {
            if (!isset($meetings[$index])) {
                continue;
            }
            $meetings[$index] = array_merge($meetings[$index], $fields);
        }

        return $meetings;
    }

    /**
     * Re-validate every meeting (overridden or not) through RuleEngine
     * right before saving.
     */
    private function validateMeetings(array $meetings, int $termId, int $sectionId): array
    {
        $violations = [];

        foreach ($meetings as $index => $meeting) {
            $result = $this->ruleEngine->validate([
                'term_id'           => $termId,
                'section_id'        => $sectionId,
                'course_id'         => $meeting['course_id'],
                'faculty_id'        => null,
                'room_id'           => $meeting['room_id'],
                'day'               => $meeting['day'],
                'start_time'        => $meeting['start_time'],
                'end_time'          => $meeting['end_time'],
                'mode'              => $meeting['mode'],
                'is_hybrid'         => $meeting['is_hybrid'] ?? false,
                'preferred_pattern' => $meeting['preferred_pattern'] ?? null,
            ]);

            foreach ($result as $violation) {
                $violation['meeting_index'] = $index;
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Explicitly reject a single recommendation.
     */
    public function reject(int $recommendationId, int $userId, ?string $reason = null): array
    {
        $recommendation = ScheduleRecommendation::findOrFail($recommendationId);

        $recommendation->update([
            'status'           => 'rejected',
            'rejected_by'      => $userId,
            'rejected_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        return ['message' => 'Recommendation rejected.'];
    }

    /**
     * Derive each meeting's 'mode' from its course's category —
     * pathfit/nstp courses always meet in the field, everything else
     * defaults to on-site.
     *
     * Fix #10: When the mode is corrected to 'field', also update the room_id
     * to the real field-type room from the database, so the RuleEngine does
     * not reject the schedule at accept-time for a room-type mismatch.
     */
    private function applyMode(array $meetings, $courseCategoryMap): array
    {
        // Lazy-load the canonical field room ID once so we don't query per-meeting.
        $fieldRoomId = null;

        foreach ($meetings as &$meeting) {
            $category = $courseCategoryMap[$meeting['course_id']] ?? null;
            $isField  = in_array($category, self::FIELD_CATEGORIES, true);

            if ($isField) {
                $meeting['mode'] = 'field';

                // Fix #10: Ensure the room_id points to an actual field-type room.
                if ($fieldRoomId === null) {
                    $fieldRoomId = Rooms::where('room_type', 'field')
                        ->value('id') ?? $meeting['room_id'];
                }
                $meeting['room_id'] = $fieldRoomId;
            } else {
                $meeting['mode'] = $meeting['mode'] ?? 'on-site';
            }
        }

        return $meetings;
    }
}
