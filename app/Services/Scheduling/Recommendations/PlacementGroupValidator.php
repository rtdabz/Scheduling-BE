<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Generation\ScheduleRequirement;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Closure;
use InvalidArgumentException;

final readonly class PlacementGroupValidator
{
    public function __construct(private SchedulingConstraintKernel $kernel) {}

    /**
     * @param  list<array<string, mixed>>  $rows  Complete proposed and kept affected meetings.
     * @param  list<array<string, mixed>>  $tentative  Other unsaved meetings, without the affected group.
     * @param  list<int>  $ignoreIds  Exact persisted rows replaced by this placement.
     */
    public function passes(SchedulingSnapshot $snapshot, array $rows, array $tentative = [], array $ignoreIds = []): bool
    {
        return ($this->forContext($snapshot, $tentative, $ignoreIds))($rows);
    }

    /**
     * Prepare fixed occupancy once; every candidate still receives row and group checks.
     * An optional description is reusable when candidates retain the meeting count,
     * component kinds and consecutive marker, as manual placement does.
     *
     * @return Closure(array): bool
     */
    public function forContext(SchedulingSnapshot $snapshot, array $tentative = [], array $ignoreIds = [], ?SessionDescription $session = null): Closure
    {
        $snapshot = self::discoverySnapshot($snapshot, $tentative, $ignoreIds);
        try {
            $others = array_map(ScheduleRow::fromArray(...), array_values(array_filter(
                $tentative, static fn (array $row): bool => ! in_array((int) ($row['id'] ?? 0), $ignoreIds, true),
            )));
        } catch (InvalidArgumentException) {
            return static fn (array $rows): bool => false;
        }

        return fn (array $rows): bool => $this->passesRows($snapshot, $rows, $others, $ignoreIds, $session);
    }

    /** @param list<ScheduleRow> $others */
    private function passesRows(SchedulingSnapshot $snapshot, array $rows, array $others, array $ignoreIds, ?SessionDescription $session): bool
    {
        if ($rows === []) {
            return false;
        }
        try {
            $parsed = array_map(ScheduleRow::fromArray(...), $rows);
            $first = $parsed[0];
            $description = $session ?? SessionInterpreter::fromRows($rows);
            if (count($parsed) !== $description->expectedMeetings
                || ($first->splitGroupId !== null && count($parsed) < 2)) {
                return false;
            }
            foreach ($parsed as $index => $row) {
                if ($row->semesterId !== $snapshot->semesterId || $row->sectionId !== $first->sectionId
                    || $row->courseId !== $first->courseId
                    || $row->departmentId !== (int) ($snapshot->sectionsById[$row->sectionId]['department_id'] ?? 0)
                    || ! isset($snapshot->coursesById[$row->courseId])
                    || ($row->roomId !== null && ! isset($snapshot->roomsById[$row->roomId]))
                    || ($row->facultyId !== null && ! isset($snapshot->facultiesById[$row->facultyId]))
                    || (count($parsed) > 1 && ($row->splitGroupId === null || $row->splitGroupId !== $first->splitGroupId))
                    || $row->preferredPattern !== $first->preferredPattern) {
                    return false;
                }
                $partners = array_values(array_filter($parsed, static fn (ScheduleRow $other, int $key): bool => $key !== $index, ARRAY_FILTER_USE_BOTH));
                if ($this->kernel->evaluateRow($row, $snapshot, [...$others, ...$partners], $ignoreIds) !== []) {
                    return false;
                }
            }
            $type = match (true) {
                SchedulingPolicy::consecutiveDayCount($first->preferredPattern) !== null => 'consecutive',
                in_array(true, array_map(static fn (ScheduleRow $row): bool => $row->isHybrid, $parsed), true) => 'hybrid',
                SchedulingPolicy::isFixedMeetingPattern($first->preferredPattern) => 'minor_split',
                count($parsed) > 1 => 'multi_day',
                default => 'single',
            };
            $requirements = array_map(static fn (ScheduleRow $row): ScheduleRequirement => new ScheduleRequirement(
                $row->courseId, $row->meetingType ?? 'lecture',
                intdiv(SchedulingPolicy::timeToMinutes($row->endTime) - SchedulingPolicy::timeToMinutes($row->startTime), SchedulingPolicy::SLOT_MINUTES),
                [], [$row->mode],
            ), $parsed);

            return $this->kernel->evaluateMeetingGroup(new MeetingGroup(
                $first->splitGroupId ?? 'single', $first->sectionId, $first->courseId, $type, $requirements, $parsed,
            ), $snapshot) === [];
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Do not count a displayed persisted row again as tentative occupancy. */
    public static function discoverySnapshot(SchedulingSnapshot $snapshot, array $tentative, array $ignoreIds = []): SchedulingSnapshot
    {
        $replacedIds = [...$ignoreIds, ...array_filter(array_column($tentative, 'id'))];
        if ($replacedIds === []) {
            return $snapshot;
        }
        $data = $snapshot->toArray();
        $data['persisted_schedules'] = array_values(array_filter($snapshot->persistedSchedules,
            static fn (array $row): bool => ! in_array((int) ($row['id'] ?? 0), array_map('intval', $replacedIds), true)));

        return SchedulingSnapshot::fromArray($data);
    }
}
