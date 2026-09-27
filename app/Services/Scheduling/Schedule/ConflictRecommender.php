<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;

/**
 * A few ranked, one-click fixes for one detected conflict.
 *
 * Candidates come from AvailableSlotFinder (every placement the constraint
 * kernel allows for the class) and from the department's active instructors.
 * The finder does not know who teaches the class, so each candidate is then
 * put through the same RuleEngine check ResolveScheduleConflict runs before it
 * writes: an option listed here is one the resolve endpoint should accept. It
 * is still a suggestion, not a reservation -- resolve re-checks under the lock.
 *
 * Every option carries `payload`, the exact body for
 * POST /api/conflicts/{id}/resolve, so the client applies it without mapping.
 *
 * Read-only: no locks, no writes.
 */
final class ConflictRecommender
{
    public const DEFAULT_LIMIT = 5;

    /**
     * Candidates checked against the RuleEngine before giving up. Each check
     * queries, and a busy instructor can refuse most of a room's free week.
     */
    private const MAX_VALIDATIONS = 60;

    /** Candidates per (class, action) kept before merging, so one kind cannot fill the list. */
    private const PER_BUCKET = 2;

    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly AvailableSlotFinder $slotFinder,
        private readonly RuleEngine $ruleEngine,
        private readonly ManualHybridFacultyAssignmentResolver $hybridAssignments,
        private readonly FacultyLoadService $facultyLoad,
    ) {}

    /**
     * @return list<array<string, mixed>> ranked best first
     */
    public function recommend(ScheduleConflictCase $case, int $limit = self::DEFAULT_LIMIT): array
    {
        $allowed = $case->resolutionOptions();
        $schedules = Schedule::query()->whereIn('id', $case->scheduleIds())->get()->keyBy('id');
        $budget = self::MAX_VALIDATIONS;
        $buckets = [];

        foreach ($case->scheduleIds() as $scheduleId) {
            $schedule = $schedules->get($scheduleId);
            if (! $schedule instanceof Schedule) {
                continue;
            }

            foreach ($this->placementCandidates($schedule, $allowed) as $candidates) {
                $buckets[] = $this->firstValid($candidates, self::PER_BUCKET, $budget);
            }

            if (in_array('reassign_instructor', $allowed, true)) {
                $buckets[] = $this->instructorOptions($schedule, $case->semesterId, self::PER_BUCKET);
            }
        }

        $options = array_merge(...$buckets ?: [[]]);
        usort($options, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $ranked = [];
        foreach (array_slice($options, 0, max(1, $limit)) as $index => $option) {
            unset($option['attempt']);
            $ranked[] = ['rank' => $index + 1, ...$option];
        }

        return $ranked;
    }

    /**
     * Free placements for the class, split by the action that would use them
     * and ranked within each: the same time in another room first, then the
     * same day, then days further from the one it clashed on.
     *
     * @param  list<string>  $allowed
     * @return array<string, list<array<string, mixed>>>
     */
    private function placementCandidates(Schedule $schedule, array $allowed): array
    {
        $wanted = array_intersect($allowed, ['change_room', 'change_delivery_mode', 'move_schedule']);
        if ($wanted === []
            || ! in_array($schedule->status, SameTimePartnerMover::EDITABLE_STATUSES, true)
            // A Consecutive Days run moves as a block; one day of it has no
            // free slot of its own to offer.
            || SchedulingPolicy::consecutiveDayCount($schedule->preferred_pattern) !== null) {
            return [];
        }

        $start = SchedulingPolicy::timeToMinutes((string) $schedule->start_time);
        $durationMinutes = SchedulingPolicy::timeToMinutes((string) $schedule->end_time) - $start;
        if ($durationMinutes <= 0 || $durationMinutes % SchedulingPolicy::SLOT_MINUTES !== 0) {
            return [];
        }

        $snapshot = $this->snapshots->capture(
            semesterId: (int) $schedule->semester_id,
            departmentId: (int) $schedule->department_id,
            sectionIds: [(int) $schedule->section_id],
            courseIds: [(int) $schedule->course_id],
        );

        $found = $this->slotFinder->find(
            snapshot: $snapshot,
            sectionId: (int) $schedule->section_id,
            courseId: (int) $schedule->course_id,
            durationSlots: intdiv($durationMinutes, SchedulingPolicy::SLOT_MINUTES),
            ignoreScheduleIds: [(int) $schedule->id],
            meetingType: $schedule->meeting_type,
            searchFromDay: (string) $schedule->day,
        );

        $day = (string) $schedule->day;
        $startTime = SchedulingPolicy::normalizeTime((string) $schedule->start_time);
        $mode = (string) $schedule->mode;
        $roomId = $schedule->room_id !== null ? (int) $schedule->room_id : null;
        $candidates = [];
        $seenMoves = [];

        foreach ($found['slots'] as $slot) {
            $sameTime = $slot['day'] === $day && $slot['start_time'] === $startTime;
            $action = match (true) {
                $sameTime && $slot['mode'] === $mode => 'change_room',
                $sameTime => 'change_delivery_mode',
                default => 'move_schedule',
            };

            if (! in_array($action, $wanted, true) || ($sameTime && $slot['mode'] === $mode && $slot['room_id'] === $roomId)) {
                continue;
            }

            // One move per day and time: the best room for it is enough, and
            // five rooms at the same hour are not five different fixes.
            if ($action === 'move_schedule') {
                $key = $slot['day'].'@'.$slot['start_time'];
                $keepsRoom = $slot['room_id'] === $roomId && $slot['mode'] === $mode;
                if (isset($seenMoves[$key]) && ! $keepsRoom) {
                    continue;
                }
                $seenMoves[$key] = true;
            }

            $candidates[$action][] = $this->placementOption($schedule, $action, $slot, $start);
        }

        foreach (array_keys($candidates) as $action) {
            usort($candidates[$action], static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function placementOption(Schedule $schedule, string $action, array $slot, int $currentStart): array
    {
        $mode = (string) $slot['mode'];
        $roomId = $slot['room_id'];
        $keepsRoom = $roomId === ($schedule->room_id !== null ? (int) $schedule->room_id : null);
        $keepsMode = $mode === $schedule->mode;
        $startTime = substr((string) $slot['start_time'], 0, 5);
        $endTime = substr((string) $slot['end_time'], 0, 5);
        $where = $mode === 'online' ? 'online' : 'in '.$slot['room_code'];

        [$score, $payload, $summary] = match ($action) {
            'change_room' => [
                100,
                ['room_id' => $roomId, 'mode' => $mode],
                "Move {$this->label($schedule)} to {$slot['room_code']}, same time.",
            ],
            'change_delivery_mode' => [
                85,
                ['mode' => $mode, 'room_id' => $roomId],
                "Hold {$this->label($schedule)} {$where} instead, same time.",
            ],
            default => [
                // Nearer the original day and hour is less disruptive; keeping
                // the room and the delivery mode more so.
                70
                    - 6 * SchedulingPolicy::searchDayRank((string) $slot['day'], (string) $schedule->day)
                    - intdiv(abs(SchedulingPolicy::timeToMinutes((string) $slot['start_time']) - $currentStart), 60)
                    + ($keepsRoom ? 4 : 0)
                    - ($keepsMode ? 0 : 15),
                ['day' => $slot['day'], 'start_time' => $startTime, 'end_time' => $endTime, 'room_id' => $roomId, 'mode' => $mode],
                "Move {$this->label($schedule)} to {$slot['day']} {$startTime}-{$endTime} {$where}.",
            ],
        };

        return [
            'action' => $action,
            'schedule_id' => (int) $schedule->id,
            'summary' => $summary,
            'score' => $score,
            'day' => $slot['day'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'mode' => $mode,
            'room_id' => $roomId,
            'room_code' => $slot['room_code'],
            'payload' => ['action' => $action, 'schedule_id' => (int) $schedule->id, ...$payload],
            'attempt' => [
                ...$schedule->toArray(),
                ...$payload,
                'room_id' => $mode === 'online' ? null : $roomId,
                'ignore_schedule_id' => (int) $schedule->id,
            ],
        ];
    }

    /**
     * The first candidates the RuleEngine accepts, in their ranked order.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function firstValid(array $candidates, int $take, int &$budget): array
    {
        $valid = [];
        foreach ($candidates as $candidate) {
            if (count($valid) >= $take || $budget <= 0) {
                break;
            }
            $budget--;

            if ($this->ruleEngine->validate($candidate['attempt']) !== []) {
                continue;
            }

            // A split meeting whose partner must share its time would drag the
            // partner along on resolve; this candidate only checked itself.
            $partners = $this->splitPartners($candidate['attempt']);
            if ($partners !== [] && collect($this->ruleEngine->validateConfiguredMeetingGroups([$candidate['attempt'], ...$partners]))
                ->contains('rule', 'split_group_same_time')) {
                continue;
            }

            $valid[] = $candidate;
        }

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    private function splitPartners(array $attempt): array
    {
        if (($attempt['split_group_id'] ?? null) === null) {
            return [];
        }

        return Schedule::query()
            ->whereKeyNot($attempt['id'])
            ->whereHas('split', static fn ($query) => $query->where('split_group_id', $attempt['split_group_id']))
            ->get()
            ->map(static fn (Schedule $partner): array => $partner->toArray())
            ->all();
    }

    /**
     * Active instructors of the class's department who are free for it (and
     * for any meeting that is assigned with it), lightest load first. One that
     * would land in pro bono is still offered, ranked last and flagged: the
     * resolve endpoint will ask for confirmation.
     *
     * @return list<array<string, mixed>>
     */
    private function instructorOptions(Schedule $schedule, int $semesterId, int $take): array
    {
        $group = $this->hybridAssignments->resolve($schedule);
        $groupIds = $group->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $units = (int) (Course::query()->whereKey($schedule->course_id)->value('units') ?? 0);
        $pair = ['section_id' => (int) $schedule->section_id, 'course_id' => (int) $schedule->course_id, 'units' => $units];

        $options = [];
        $faculties = Faculty::query()
            ->where('department_id', $schedule->department_id)
            ->where('status', 'active')
            ->when($schedule->faculty_id !== null, fn ($query) => $query->whereKeyNot($schedule->faculty_id))
            ->orderBy('last_name')
            ->get();

        foreach ($faculties as $faculty) {
            $free = true;
            foreach ($group as $meeting) {
                if ($this->ruleEngine->validateInstructorAssignment([
                    ...$meeting->toArray(),
                    'faculty_id' => (int) $faculty->id,
                    'ignore_schedule_id' => $groupIds,
                ]) !== []) {
                    $free = false;
                    break;
                }
            }
            if (! $free) {
                continue;
            }

            $load = $this->facultyLoad->projectLoad($faculty, $semesterId, [$pair]);
            $name = trim("{$faculty->first_name} {$faculty->last_name}");
            $options[] = [
                'action' => 'reassign_instructor',
                'schedule_id' => (int) $schedule->id,
                'summary' => "Assign {$name} to {$this->label($schedule)}.",
                // Below every same-time room change, above most moves: a new
                // instructor leaves the timetable alone.
                'score' => ($load['requires_confirmation'] ? 40 : 90) - min(30, (int) $load['projected_units']),
                'faculty_id' => (int) $faculty->id,
                'faculty_name' => $name,
                'projected_units' => (int) $load['projected_units'],
                'requires_overload_confirmation' => (bool) $load['requires_confirmation'],
                'payload' => [
                    'action' => 'reassign_instructor',
                    'schedule_id' => (int) $schedule->id,
                    'faculty_id' => (int) $faculty->id,
                ],
            ];
        }

        usort($options, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($options, 0, $take);
    }

    /** @var array<int, string> course code by course id, for option summaries */
    private array $labels = [];

    private function label(Schedule $schedule): string
    {
        $courseId = (int) $schedule->course_id;

        return $this->labels[$courseId] ??= (string) (Course::query()->whereKey($courseId)->value('course_code') ?? 'this class');
    }
}
