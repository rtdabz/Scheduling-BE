<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Manual;

use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Support\SchedulingPolicy;
use InvalidArgumentException;

final class AvailableSlotFinder
{
    public const MAX_SLOTS = 2000;

    public function __construct(private readonly SchedulingConstraintKernel $kernel) {}

    public const MODES = ['on-site', 'online', 'field'];

    /**
     * @param  list<string>  $modes  deliveries to walk; defaults to all of them
     * @param  list<string>  $excludedDays  days this meeting may not use
     * @param  list<array<string, mixed>>  $tentativeSchedules  unsaved rows the dialog is holding
     * @param  list<int>  $ignoreScheduleIds  rows being replaced by this placement
     * @param  string|null  $searchFromDay  the day listed first; the other weekdays follow, the weekend last
     * @param  int|null  $consecutiveDays  a Consecutive Days run: only a start and room free on every day of
     *                                     a run of this many days is listed, on the run's first day. The
     *                                     run is the section's ticked meeting days when its rule has them,
     *                                     else any run of this many back-to-back days
     * @param  array<string, mixed>|null  $rowTemplate  Affected meeting facts for group discovery; complete-group validation follows enumeration.
     * @return array{
     *     slots: list<array<string, mixed>>,
     *     rooms: list<array{room_id: int|null, room_code: string, room_type: string, mode: string, slot_count: int}>,
     *     total: int,
     *     truncated: bool,
     * }
     */
    public function find(
        SchedulingSnapshot $snapshot,
        int $sectionId,
        int $courseId,
        int $durationSlots,
        array $modes = self::MODES,
        array $tentativeSchedules = [],
        array $ignoreScheduleIds = [],
        ?string $meetingType = null,
        array $excludedDays = [],
        ?string $searchFromDay = null,
        ?int $consecutiveDays = null,
        ?array $rowTemplate = null,
    ): array {
        if ($durationSlots <= 0) {
            throw new InvalidArgumentException('A meeting must be at least one slot long.');
        }
        $isRun = $consecutiveDays !== null;

        $section = $snapshot->sectionsById[$sectionId] ?? null;
        $course = $snapshot->coursesById[$courseId] ?? null;
        if (! is_array($section) || ! is_array($course)) {
            return ['slots' => [], 'rooms' => [], 'total' => 0, 'truncated' => false];
        }

        if (SchedulingConstraintPredicates::isLaboratoryCourse($course)) {
            $modes = array_values(array_filter($modes, static fn (string $mode): bool => $mode !== 'field'));
        }

        $candidateRows = $this->toCandidateRows($tentativeSchedules, $ignoreScheduleIds);

        $totalSlots = SchedulingPolicy::totalSlots();
        $starts = [];
        foreach (SchedulingPolicy::generatedStartSlotsForDuration($durationSlots) as $startSlot) {
            $endSlot = $startSlot + $durationSlots;
            if ($endSlot > $totalSlots) {
                continue;
            }

            $starts[] = [
                'start_slot' => $startSlot,
                'end_slot' => $endSlot,
                'start_time' => SchedulingPolicy::slotToTime($startSlot),
                'end_time' => SchedulingPolicy::slotToTime($endSlot),
            ];
        }

        $days = array_values(array_diff(SchedulingPolicy::PERSISTABLE_DAYS, $excludedDays));
        $slots = [];
        $countsByRoom = [];
        $truncated = false;
        $freeByPlacement = [];

        foreach ($this->candidateRooms($snapshot, $modes) as $room) {
            $roomId = $room['room_id'];
            $mode = $room['mode'];
            $roomKey = $this->roomKey($mode, $roomId);
            $countsByRoom[$roomKey] = [
                'room_id' => $roomId,
                'room_code' => $room['room_code'],
                'room_type' => $room['room_type'],
                'mode' => $mode,
                'slot_count' => 0,
            ];

            foreach ($days as $day) {
                foreach ($starts as ['start_slot' => $startSlot, 'end_slot' => $endSlot, 'start_time' => $startTime, 'end_time' => $endTime]) {
                    $row = new ScheduleRow(
                        semesterId: $snapshot->semesterId,
                        sectionId: $sectionId,
                        courseId: $courseId,
                        departmentId: (int) ($section['department_id'] ?? $snapshot->departmentId),
                        day: $day,
                        startTime: $startTime,
                        endTime: $endTime,
                        mode: $mode,
                        roomId: $roomId,
                        meetingType: $meetingType,
                        facultyId: isset($rowTemplate['faculty_id']) ? (int) $rowTemplate['faculty_id'] : null,
                        isHybrid: (bool) ($rowTemplate['is_hybrid'] ?? false),
                        preferredPattern: $rowTemplate['preferred_pattern'] ?? null,
                        splitGroupId: $rowTemplate['split_group_id'] ?? null,
                    );

                    if ($this->kernel->evaluateRow($row, $snapshot, $candidateRows, $ignoreScheduleIds) !== []) {
                        continue;
                    }

                    if ($isRun) {
                        $freeByPlacement[$roomKey.'@'.$startSlot][$day] = true;

                        continue;
                    }

                    $countsByRoom[$roomKey]['slot_count']++;

                    if (count($slots) >= self::MAX_SLOTS) {
                        $truncated = true;

                        continue;
                    }

                    $slots[] = [
                        'day' => $day,
                        'day_index' => SchedulingPolicy::dayIndex($day),
                        'start_slot' => $startSlot,
                        'end_slot' => $endSlot,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'mode' => $mode,
                        'room_id' => $roomId,
                        'room_code' => $room['room_code'],
                        'room_type' => $room['room_type'],
                    ];
                }
            }
        }

        if ($isRun) {
            $rule = $snapshot->consecutiveDayRulesFor($sectionId)[$courseId] ?? null;
            $runs = ($rule['meeting_days'] ?? null) !== null && count($rule['meeting_days']) === $consecutiveDays
                ? [$rule['meeting_days']]
                : SchedulingPolicy::consecutiveDayRuns(
                    $consecutiveDays,
                    (bool) ($snapshot->departmentSettings['sunday_classes_enabled'] ?? false),
                );
            [$slots, $truncated] = $this->runSlots($freeByPlacement, $starts, $runs, $countsByRoom);
        }

        $dayOrder = static fn (array $slot): int => $searchFromDay !== null
            ? SchedulingPolicy::searchDayRank($slot['day'], $searchFromDay)
            : $slot['day_index'];
        usort($slots, static fn (array $left, array $right): int => [$dayOrder($left), $left['start_slot'], $left['room_code']]
            <=> [$dayOrder($right), $right['start_slot'], $right['room_code']]);

        $rooms = array_values(array_filter(
            $countsByRoom,
            static fn (array $room): bool => $room['slot_count'] > 0,
        ));
        usort($rooms, static fn (array $left, array $right): int => $right['slot_count'] <=> $left['slot_count']
            ?: strcmp($left['room_code'], $right['room_code']));

        return [
            'slots' => $slots,
            'rooms' => $rooms,
            'total' => array_sum(array_column($rooms, 'slot_count')),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, array<string, true>>  $freeByPlacement  "roomKey@startSlot" => free days
     * @param  list<array{start_slot: int, end_slot: int, start_time: string, end_time: string}>  $starts
     * @param  list<list<string>>  $runs  the day sets the run may take
     * @param  array<string, array{room_id: int|null, room_code: string, room_type: string, mode: string, slot_count: int}>  $countsByRoom
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function runSlots(array $freeByPlacement, array $starts, array $runs, array &$countsByRoom): array
    {
        $startsBySlot = [];
        foreach ($starts as $start) {
            $startsBySlot[$start['start_slot']] = $start;
        }

        $slots = [];
        $truncated = false;
        foreach ($freeByPlacement as $placement => $freeDays) {
            [$roomKey, $startSlot] = explode('@', $placement);
            $room = $countsByRoom[$roomKey];
            $start = $startsBySlot[(int) $startSlot];

            foreach ($runs as $run) {
                if (array_diff($run, array_keys($freeDays)) !== []) {
                    continue;
                }

                $countsByRoom[$roomKey]['slot_count']++;
                if (count($slots) >= self::MAX_SLOTS) {
                    $truncated = true;

                    continue;
                }

                $slots[] = [
                    'day' => $run[0],
                    'day_index' => SchedulingPolicy::dayIndex($run[0]),
                    'run_days' => $run,
                    ...$start,
                    'mode' => $room['mode'],
                    'room_id' => $room['room_id'],
                    'room_code' => $room['room_code'],
                    'room_type' => $room['room_type'],
                ];
            }
        }

        return [$slots, $truncated];
    }

    /**
     * @param  list<string>  $modes
     * @return list<array{room_id: int|null, room_code: string, room_type: string, mode: string}>
     */
    private function candidateRooms(SchedulingSnapshot $snapshot, array $modes): array
    {
        $candidates = [];

        foreach ($modes as $mode) {
            if (! in_array($mode, self::MODES, true)) {
                continue;
            }

            if ($mode === 'online') {
                $candidates[] = ['room_id' => null, 'room_code' => 'Online', 'room_type' => 'online', 'mode' => 'online'];

                continue;
            }

            foreach ($snapshot->roomsById as $roomId => $room) {
                $roomType = (string) ($room['room_type'] ?? '');
                $status = (string) ($room['status'] ?? 'available');

                if ($status !== '' && $status !== 'available') {
                    continue;
                }

                if ($mode === 'field' ? $roomType !== 'field' : ! in_array($roomType, ['lecture', 'laboratory'], true)) {
                    continue;
                }

                $candidates[] = [
                    'room_id' => (int) $roomId,
                    'room_code' => (string) ($room['room_code'] ?? ('Room '.$roomId)),
                    'room_type' => $roomType,
                    'mode' => $mode,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array<string, mixed>>  $tentativeSchedules
     * @param  list<int>  $ignoreScheduleIds
     * @return list<ScheduleRow>
     */
    private function toCandidateRows(array $tentativeSchedules, array $ignoreScheduleIds): array
    {
        $rows = [];
        foreach ($tentativeSchedules as $schedule) {
            if (! is_array($schedule)) {
                continue;
            }
            if (in_array((int) ($schedule['id'] ?? 0), $ignoreScheduleIds, true)) {
                continue;
            }

            try {
                $rows[] = ScheduleRow::fromArray($schedule);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $rows;
    }

    private function roomKey(string $mode, ?int $roomId): string
    {
        return $mode.':'.($roomId ?? 'virtual');
    }
}
