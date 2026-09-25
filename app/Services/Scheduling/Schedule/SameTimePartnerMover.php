<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Exceptions\ScheduleConflictException;
use App\Models\Schedule;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A Split Session or Hybrid Split meets at one time on both days
 * (MeetingGroupRule's `split_group_same_time`), so moving one meeting to a new
 * time carries its partner to that time, on the partner's own day. Other linked
 * groups keep their own times and are only checked as a group.
 *
 * A Consecutive Days run moves as a whole: the other days take the new time
 * and shift by the same number of days, so Thursday-Saturday dragged a day
 * later is Friday-Sunday.
 *
 * Extracted from ScheduleController so the drag-relocate path and the conflict
 * resolution path move a pair the same way. A second implementation of this
 * would let one path write a pair to two different times, which is precisely
 * the state the rule exists to prevent.
 */
final class SameTimePartnerMover
{
    /** Statuses whose timetable placement may still be edited. */
    public const EDITABLE_STATUSES = ['draft', 'completed', 'revision'];

    private const TIME_FIELDS = ['day', 'start_time', 'end_time'];

    public function __construct(private readonly RuleEngine $ruleEngine) {}

    /**
     * The linked meetings a relocation must be judged with, or an empty
     * collection when nothing about the placement's timing is changing.
     *
     * @param  array<string, mixed>  $changes
     * @return Collection<int, Schedule>
     */
    public function partnersFor(Schedule $schedule, array $changes): Collection
    {
        $relocating = collect(self::TIME_FIELDS)->contains(
            static fn (string $field): bool => array_key_exists($field, $changes),
        );

        if ($schedule->split_group_id === null || ! $relocating) {
            return collect();
        }

        return Schedule::query()
            ->whereKeyNot($schedule->id)
            ->whereHas('split', static fn (Builder $query) => $query->where('split_group_id', $schedule->split_group_id))
            ->get();
    }

    /**
     * The other days of a Consecutive Days run that is moving. The moved day
     * is judged without them: shifted one day, it lands where one of them
     * stands now, and they move with it.
     *
     * @param  Collection<int, Schedule>  $partners
     * @return list<int>
     */
    public function runPartnerIds(Schedule $schedule, Collection $partners): array
    {
        if (SchedulingPolicy::consecutiveDayCount($schedule->preferred_pattern) === null) {
            return [];
        }

        return $partners->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $attemptData
     * @param  Collection<int, Schedule>  $partners
     * @return list<int> the partners moved to the new time
     *
     * @throws ScheduleConflictException
     */
    public function move(array $attemptData, Collection $partners): array
    {
        $partners = $partners->values();
        $partnerRows = $partners->map(static fn (Schedule $partner): array => $partner->toArray())->all();
        $time = ['start_time' => $attemptData['start_time'], 'end_time' => $attemptData['end_time']];
        $isRun = SchedulingPolicy::consecutiveDayCount($attemptData['preferred_pattern'] ?? null) !== null;

        if ($isRun) {
            $partnerRows = $this->shiftedRun($attemptData, $partnerRows, $time);
            $movesPartners = true;
        } else {
            $movesPartners = collect($this->ruleEngine->validateConfiguredMeetingGroups([$attemptData, ...$partnerRows]))
                ->contains('rule', 'split_group_same_time');
            if ($movesPartners) {
                $partnerRows = array_map(static fn (array $row): array => [...$row, ...$time], $partnerRows);
            }
        }

        $groupViolations = $this->ruleEngine->validateConfiguredMeetingGroups([$attemptData, ...$partnerRows]);
        if ($groupViolations !== []) {
            throw new ScheduleConflictException($groupViolations, 'Schedule update breaks its linked meetings.');
        }

        if (! $movesPartners) {
            return [];
        }

        $partnerIds = $partners->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $ignoreIds = [(int) $attemptData['id'], ...$partnerIds];
        foreach ($partners as $index => $partner) {
            if (! in_array($partner->status, self::EDITABLE_STATUSES, true)) {
                throw new ScheduleConflictException([[
                    'rule' => 'split_group_same_time',
                    'message' => 'The paired meeting is locked at its current approval stage, so this meeting cannot move to a new time.',
                ]], 'Schedule update breaks its linked meetings.');
            }

            $violations = $this->ruleEngine->validate([...$partnerRows[$index], 'ignore_schedule_id' => $ignoreIds]);
            if ($violations !== []) {
                throw new ScheduleConflictException($violations, $isRun
                    ? sprintf('The run\'s %s meeting cannot move to %s at the new time.', (string) $partner->day, (string) $partnerRows[$index]['day'])
                    : 'The paired meeting cannot move to the new time.');
            }

            $partner->update($isRun ? [...$time, 'day' => $partnerRows[$index]['day']] : $time);
        }

        return $partnerIds;
    }

    /**
     * The run's other days at the new time, each shifted by as many days as
     * the moved meeting was. A shift past the end of the week is refused
     * here; a day the department does not teach is the Rule Engine's.
     *
     * @param  array<string, mixed>  $attemptData
     * @param  list<array<string, mixed>>  $partnerRows
     * @param  array{start_time: mixed, end_time: mixed}  $time
     * @return list<array<string, mixed>>
     *
     * @throws ScheduleConflictException
     */
    private function shiftedRun(array $attemptData, array $partnerRows, array $time): array
    {
        $previousDay = (string) (Schedule::query()->whereKey((int) ($attemptData['id'] ?? 0))->value('day') ?? $attemptData['day']);
        $shift = SchedulingPolicy::dayIndex((string) $attemptData['day']) - SchedulingPolicy::dayIndex($previousDay);

        return array_map(static function (array $row) use ($shift, $time, $attemptData): array {
            $index = SchedulingPolicy::dayIndex((string) $row['day']) + $shift;
            if (! isset(SchedulingPolicy::DAYS[$index])) {
                throw new ScheduleConflictException([[
                    'rule' => 'consecutive_days',
                    'message' => sprintf(
                        'Moving this meeting to %s would push its %s meeting past the end of the week. Move the run to an earlier day.',
                        (string) $attemptData['day'],
                        (string) $row['day'],
                    ),
                ]], 'Schedule update breaks its linked meetings.');
            }

            return [...$row, ...$time, 'day' => SchedulingPolicy::DAYS[$index]];
        }, $partnerRows);
    }
}
