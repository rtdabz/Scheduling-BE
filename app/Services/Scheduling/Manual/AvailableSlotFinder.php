<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Manual;

use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Support\SchedulingPolicy;
use InvalidArgumentException;

/**
 * Every placement the rules actually allow for one meeting of one course.
 *
 * The CSP solver answers "give me a good timetable" and stops at a handful of
 * ranked solutions; this answers "where could this class go at all", which is
 * what the placement dialog needs when the suggested slot collides. It walks
 * Monday to Sunday, every start on the generated grid and every room the
 * department can reach, and keeps the combinations the constraint kernel
 * accepts -- the same kernel the save path validates against, so a slot offered
 * here is a slot that will save.
 *
 * Pure apart from the snapshot it is handed: no queries, so the whole week
 * across every room costs one snapshot capture rather than one per candidate.
 */
final class AvailableSlotFinder
{
    /**
     * Hard ceiling on returned slots. A wide-open week across many rooms can
     * reach a few thousand; past this the list stops being something a person
     * reads and starts being a payload. Callers are told when it bit.
     */
    public const MAX_SLOTS = 2000;

    public function __construct(private readonly SchedulingConstraintKernel $kernel) {}

    /**
     * Every delivery a meeting may legally use. All three are walked and the
     * kernel throws out the ones this course cannot take -- a laboratory course
     * has no online candidates, a field course no on-site ones -- so the caller
     * never has to work out in advance which modes to ask about. Locking this
     * to the meeting's current mode hid Online entirely from a split whose
     * second meeting was online.
     */
    public const MODES = ['on-site', 'online', 'field'];

    /**
     * @param  list<string>  $modes  deliveries to walk; defaults to all of them
     * @param  list<string>  $excludedDays  days this meeting may not use
     * @param  list<array<string, mixed>>  $tentativeSchedules  unsaved rows the dialog is holding
     * @param  list<int>  $ignoreScheduleIds  rows being replaced by this placement
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
    ): array {
        if ($durationSlots <= 0) {
            throw new InvalidArgumentException('A meeting must be at least one slot long.');
        }

        $section = $snapshot->sectionsById[$sectionId] ?? null;
        $course = $snapshot->coursesById[$courseId] ?? null;
        if (! is_array($section) || ! is_array($course)) {
            return ['slots' => [], 'rooms' => [], 'total' => 0, 'truncated' => false];
        }

        // RoomTypeRule accepts any course in a field room, so the kernel alone
        // would offer the field to a laboratory course. ValidateGenerationConfiguration
        // refuses that combination, and so does the dialog's Field button, so
        // the list must not be the one place it looks available.
        if (SchedulingConstraintPredicates::isLaboratoryCourse($course)) {
            $modes = array_values(array_filter($modes, static fn (string $mode): bool => $mode !== 'field'));
        }

        $candidateRows = $this->toCandidateRows($tentativeSchedules, $ignoreScheduleIds);

        // Times are resolved once per start rather than once per room-day:
        // slotToTime() is pure, and this loop would otherwise call it for every
        // candidate in the week.
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

            // Monday through Sunday: the day rules decide which of them survive,
            // rather than this loop assuming a five- or six-day week. The
            // caller excludes the day a linked meeting already holds, because
            // split_group_day_separation refuses two meetings of one course on
            // the same day and such a slot could only ever fail on save.
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
                    );

                    if ($this->kernel->evaluateRow($row, $snapshot, $candidateRows, $ignoreScheduleIds) !== []) {
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

        usort($slots, static fn (array $left, array $right): int => [$left['day_index'], $left['start_slot'], $left['room_code']]
            <=> [$right['day_index'], $right['start_slot'], $right['room_code']]);

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
     * One entry per (mode, room) pair to walk. Online is a single virtual room
     * -- it holds any number of classes, so enumerating real rooms for it would
     * repeat the same week once per room. Field is the department's field
     * rooms, which the kernel still checks for capacity.
     *
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
     * The dialog's unsaved rows, as the kernel's overlap family expects them.
     * A row this placement replaces is dropped: it is the thing being moved.
     *
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
                // A malformed unsaved row is context, not the subject of the
                // query: skipping it offers a slot the save may still refuse,
                // which is better than refusing to answer at all.
                continue;
            }
        }

        return $rows;
    }

    /** Online and field both carry a null room id, so the mode disambiguates. */
    private function roomKey(string $mode, ?int $roomId): string
    {
        return $mode.':'.($roomId ?? 'virtual');
    }
}
