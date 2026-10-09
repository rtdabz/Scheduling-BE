<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Exceptions\ScheduleConflictException;
use App\Models\Course;
use App\Models\Schedule;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Manual\AvailableSlotFinder;
use App\Services\Scheduling\Recommendations\PlacementGroupValidator;
use App\Services\Scheduling\Recommendations\SessionInterpreter;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Closure;
use Illuminate\Support\Collection;

final class ConflictRecommender
{
    public const DEFAULT_LIMIT = 5;

    private const MAX_VALIDATIONS = 60;

    private const PER_BUCKET = 2;

    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
        private readonly AvailableSlotFinder $slotFinder,
        private readonly RuleEngine $ruleEngine,
        private readonly SameTimePartnerMover $sameTimePartners,
        private readonly PlacementGroupValidator $groups,
    ) {}

    /**
     * @return list<array<string, mixed>> ranked best first
     */
    public function recommend(ScheduleConflictCase $case, int $limit = self::DEFAULT_LIMIT): array
    {
        $allowed = $case->resolutionOptions();
        $schedules = Schedule::query()->whereIn('id', $case->scheduleIds())->get()->keyBy('id');
        $perSchedule = intdiv(self::MAX_VALIDATIONS, max(1, count($case->scheduleIds())));
        $buckets = [];

        foreach ($case->scheduleIds() as $scheduleId) {
            $schedule = $schedules->get($scheduleId);
            if (! $schedule instanceof Schedule) {
                continue;
            }

            $budget = $perSchedule;

            $buckets = [...$buckets, ...array_values($this->placementOptions($schedule, $allowed, $budget))];
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
     * @param  list<string>  $allowed
     * @return array<string, list<array<string, mixed>>>
     */
    private function placementOptions(Schedule $schedule, array $allowed, int &$budget): array
    {
        $wanted = array_intersect($allowed, ['change_room', 'change_delivery_mode', 'move_schedule']);
        if ($wanted === []
            || ! in_array($schedule->status, SameTimePartnerMover::EDITABLE_STATUSES, true)
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

        $partners = $this->sameTimePartners->groupPartnersFor($schedule);
        $ignoreIds = [(int) $schedule->id, ...$partners->pluck('id')->map('intval')->all()];
        $session = SessionInterpreter::fromRows([$schedule->toArray(), ...$partners->map->toArray()->all()]);
        $validate = $this->groups->forContext($snapshot, ignoreIds: $ignoreIds, session: $session);

        $found = $this->slotFinder->find(
            snapshot: $snapshot,
            sectionId: (int) $schedule->section_id,
            courseId: (int) $schedule->course_id,
            durationSlots: intdiv($durationMinutes, SchedulingPolicy::SLOT_MINUTES),
            ignoreScheduleIds: $ignoreIds,
            meetingType: $schedule->meeting_type,
            searchFromDay: (string) $schedule->day,
            rowTemplate: $schedule->toArray(),
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
            $candidates[$action] = $this->firstValid($candidates[$action], self::PER_BUCKET, $budget, $partners, $validate);
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
        $modeChange = 'Changes delivery to '.$mode;

        [$score, $payload, $summary, $reasons] = match ($action) {
            'change_room' => [
                100,
                ['room_id' => $roomId, 'mode' => $mode],
                "Move {$this->label($schedule)} to {$slot['room_code']}, same time.",
                ['Same day and time', 'Only the room changes'],
            ],
            'change_delivery_mode' => [
                85,
                ['mode' => $mode, 'room_id' => $roomId],
                "Hold {$this->label($schedule)} {$where} instead, same time.",
                ['Same day and time', $modeChange],
            ],
            default => [
                70
                    - 6 * SchedulingPolicy::searchDayRank((string) $slot['day'], (string) $schedule->day)
                    - intdiv(abs(SchedulingPolicy::timeToMinutes((string) $slot['start_time']) - $currentStart), 60)
                    + ($keepsRoom ? 4 : 0)
                    - ($keepsMode ? 0 : 15),
                ['day' => $slot['day'], 'start_time' => $startTime, 'end_time' => $endTime, 'room_id' => $roomId, 'mode' => $mode],
                "Move {$this->label($schedule)} to {$slot['day']} ".ScheduleConflictCase::clock($startTime).' - '.ScheduleConflictCase::clock($endTime)." {$where}.",
                $this->moveReasons($schedule, $slot, $currentStart, $keepsRoom, $keepsMode ? null : $modeChange),
            ],
        };

        return [
            'action' => $action,
            'schedule_id' => (int) $schedule->id,
            'summary' => $summary,
            'reasons' => $reasons,
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
     * @param  array<string, mixed>  $slot
     * @return list<string>
     */
    private function moveReasons(Schedule $schedule, array $slot, int $currentStart, bool $keepsRoom, ?string $modeChange): array
    {
        $day = (string) $slot['day'];
        $reasons = [];

        if ($day === $schedule->day) {
            $reasons[] = 'Same day';
        } elseif (! in_array($day, SchedulingPolicy::WEEKDAYS, true) && in_array($schedule->day, SchedulingPolicy::WEEKDAYS, true)) {
            $reasons[] = 'Moves to the weekend';
        }

        $shift = SchedulingPolicy::timeToMinutes((string) $slot['start_time']) - $currentStart;
        if ($shift === 0) {
            $reasons[] = 'Same start time';
        } else {
            $hours = intdiv(abs($shift), 60);
            $minutes = abs($shift) % 60;
            $amount = trim(($hours > 0 ? "{$hours}h " : '').($minutes > 0 ? "{$minutes}m" : ''));
            $reasons[] = "Starts {$amount} ".($shift > 0 ? 'later' : 'earlier');
        }

        if ($keepsRoom && $slot['mode'] !== 'online') {
            $reasons[] = 'Keeps its room';
        }
        if ($modeChange !== null) {
            $reasons[] = $modeChange;
        }

        return $reasons;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function firstValid(array $candidates, int $take, int &$budget, Collection $partners, Closure $validate): array
    {
        $valid = [];
        foreach ($candidates as $candidate) {
            if (count($valid) >= $take || $budget <= 0) {
                break;
            }
            $budget--;

            try {
                $projection = $candidate['action'] === 'move_schedule'
                    ? $this->sameTimePartners->project($candidate['attempt'], $partners)
                    : ['rows' => $partners->map->toArray()->all(), 'moves_partners' => false];
            } catch (ScheduleConflictException) {
                continue;
            }
            $rows = [$candidate['attempt'], ...$projection['rows']];
            if (! $validate($rows) || $this->ruleEngine->validate($candidate['attempt']) !== []) {
                continue;
            }

            $candidate['group_rows'] = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], ...ScheduleRow::fromArray($row)->toArray(),
            ], $rows);
            $candidate['affected_schedule_ids'] = [(int) $candidate['schedule_id']];
            if ($projection['moves_partners']) {
                $candidate['affected_schedule_ids'] = [...$candidate['affected_schedule_ids'], ...$partners->pluck('id')->map('intval')->all()];
                $candidate['reasons'][] = 'Moves linked meetings to the same time';
            }
            $valid[] = $candidate;
        }

        return $valid;
    }

    /** @var array<int, string> course code by course id, for option summaries */
    private array $labels = [];

    private function label(Schedule $schedule): string
    {
        $courseId = (int) $schedule->course_id;

        return $this->labels[$courseId] ??= (string) (Course::query()->whereKey($courseId)->value('course_code') ?? 'this class');
    }
}
