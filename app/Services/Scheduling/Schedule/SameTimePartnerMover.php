<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Exceptions\ScheduleConflictException;
use App\Models\Schedule;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SameTimePartnerMover
{
    public const EDITABLE_STATUSES = ['draft', 'completed', 'revision'];

    private const TIME_FIELDS = ['day', 'start_time', 'end_time'];

    public function __construct(private readonly RuleEngine $ruleEngine) {}

    /**
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

        return $this->groupPartnersFor($schedule);
    }

    /** @return Collection<int, Schedule> */
    public function groupPartnersFor(Schedule $schedule): Collection
    {
        if ($schedule->split_group_id === null) {
            return collect();
        }

        return Schedule::query()
            ->whereKeyNot($schedule->id)
            ->whereHas('split', static fn (Builder $query) => $query->where('split_group_id', $schedule->split_group_id))
            ->get();
    }

    /**
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
        ['rows' => $partnerRows, 'moves_partners' => $movesPartners] = $this->project($attemptData, $partners);
        if (! $movesPartners) {
            return [];
        }
        $time = ['start_time' => $attemptData['start_time'], 'end_time' => $attemptData['end_time']];
        $isRun = SchedulingPolicy::consecutiveDayCount($attemptData['preferred_pattern'] ?? null) !== null;

        $partnerIds = $partners->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $ignoreIds = [(int) $attemptData['id'], ...$partnerIds];
        foreach ($partners as $index => $partner) {
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
     * Project the same partner changes for previews and writes, without saving.
     *
     * @param  Collection<int, Schedule>  $partners
     * @return array{rows: list<array<string, mixed>>, moves_partners: bool}
     * @throws ScheduleConflictException
     */
    public function project(array $attemptData, Collection $partners): array
    {
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

        if ($movesPartners && $partners->contains(
            static fn (Schedule $partner): bool => ! in_array($partner->status, self::EDITABLE_STATUSES, true),
        )) {
            throw new ScheduleConflictException([[
                'rule' => 'split_group_same_time',
                'message' => 'The paired meeting is locked at its current approval stage, so this meeting cannot move to a new time.',
            ]], 'Schedule update breaks its linked meetings.');
        }

        return ['rows' => $partnerRows, 'moves_partners' => $movesPartners];
    }

    /**
     * @param  array<string, mixed>  $attemptData
     * @param  list<array<string, mixed>>  $partnerRows
     * @param  array{start_time: mixed, end_time: mixed}  $time
     * @return list<array<string, mixed>>
     * @throws ScheduleConflictException
     */
    private function shiftedRun(array $attemptData, array $partnerRows, array $time): array
    {
        $previousDay = (string) (Schedule::query()->whereKey((int) ($attemptData['id'] ?? 0))->value('day') ?? $attemptData['day']);
        $shift = SchedulingPolicy::dayIndex((string) $attemptData['day']) - SchedulingPolicy::dayIndex($previousDay);

        $shifted = array_map(static function (array $row) use ($shift, $time, $attemptData): array {
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

        if ($shift !== 0) {
            $this->assertOnTickedDays($attemptData, [(string) $attemptData['day'], ...array_column($shifted, 'day')]);
        }

        return $shifted;
    }

    /**
     * @param  array<string, mixed>  $attemptData
     * @param  list<string>  $days
     * @throws ScheduleConflictException
     */
    private function assertOnTickedDays(array $attemptData, array $days): void
    {
        $courseId = (int) ($attemptData['course_id'] ?? 0);
        $departmentId = (int) ($attemptData['department_id'] ?? 0);
        if ($courseId <= 0 || $departmentId <= 0) {
            return;
        }

        $sectionId = isset($attemptData['section_id']) ? (int) $attemptData['section_id'] : null;
        $ticked = SchedulingPolicy::consecutiveDayRuleMap($departmentId, $sectionId, [$courseId])[$courseId]['meeting_days'] ?? null;
        if ($ticked === null || (array_diff($days, $ticked) === [] && array_diff($ticked, $days) === [])) {
            return;
        }

        throw new ScheduleConflictException([[
            'rule' => 'consecutive_days',
            'message' => sprintf(
                'This class is set to meet on %s in Setup Courses, so its run cannot move to %s. Change its time or room instead, or tick other meeting days in Setup Courses.',
                implode(', ', $ticked),
                implode(', ', SchedulingPolicy::parseMeetingDays($days) ?? $days),
            ),
        ]], 'Schedule update breaks its linked meetings.');
    }
}
