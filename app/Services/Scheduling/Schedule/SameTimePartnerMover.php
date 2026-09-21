<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Exceptions\ScheduleConflictException;
use App\Models\Schedule;
use App\Services\Scheduling\Engine\RuleEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A Split Session or Hybrid Split meets at one time on both days
 * (MeetingGroupRule's `split_group_same_time`), so moving one meeting to a new
 * time carries its partner to that time, on the partner's own day. Other linked
 * groups keep their own times and are only checked as a group.
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

        $movesPartners = collect($this->ruleEngine->validateConfiguredMeetingGroups([$attemptData, ...$partnerRows]))
            ->contains('rule', 'split_group_same_time');
        if ($movesPartners) {
            $partnerRows = array_map(static fn (array $row): array => [...$row, ...$time], $partnerRows);
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
                throw new ScheduleConflictException($violations, 'The paired meeting cannot move to the new time.');
            }

            $partner->update($time);
        }

        return $partnerIds;
    }
}
