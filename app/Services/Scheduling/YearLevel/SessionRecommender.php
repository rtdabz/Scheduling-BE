<?php

namespace App\Services\Scheduling\YearLevel;

use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * Which teaching session -- Morning, Afternoon or Evening -- still has room for
 * a course or a whole section, measured against the timetable as it stands.
 *
 * The Recommended Adjustment panel used to say "move this to the morning or
 * afternoon" as plain text. This counts the start times the generator could
 * actually use inside each session window, so a recommendation names only a
 * session that has space, and carries the adjustment that applies it.
 *
 * A start time counts when, on one of the run's days:
 *  - it is on the generator's legal start grid for the meeting's length,
 *  - the whole meeting sits inside the session window (and, for a field
 *    course, ends by the field end time),
 *  - the section is not already in class then, and
 *  - a lecture or laboratory meeting has a free room of an eligible type.
 *    Online and field meetings need no room: neither is capped.
 */
final class SessionRecommender
{
    /** @var array<int, array<string, list<array{0: int, 1: int}>>> section id => day => busy slot ranges */
    private array $sectionBusy = [];

    /** @var array<int, array<string, list<array{0: int, 1: int}>>> room id => day => busy slot ranges */
    private array $roomBusy = [];

    /**
     * @param  array<int, array<string, mixed>>  $roomsById  snapshot room records
     * @param  list<array<string, mixed>>  $occupiedRows  schedules that hold time: saved rows and this run's rows
     * @param  list<string>|null  $allowedDays  Step 1's Preferred Days; null is every day
     */
    public function __construct(
        private readonly array $roomsById,
        array $occupiedRows,
        private readonly ?array $allowedDays = null,
    ) {
        foreach ($occupiedRows as $row) {
            $range = $this->slotRange($row);
            if ($range === null) {
                continue;
            }

            $day = (string) ($row['day'] ?? '');
            $sectionId = (int) ($row['section_id'] ?? 0);
            if ($sectionId > 0) {
                $this->sectionBusy[$sectionId][$day][] = $range;
            }

            $roomId = (int) ($row['room_id'] ?? 0);
            if ($roomId > 0 && (string) ($row['mode'] ?? 'on-site') !== 'online') {
                $this->roomBusy[$roomId][$day][] = $range;
            }
        }
    }

    /**
     * Sessions a course could move to, best first. The course's own rows are
     * left out of the section's timetable: they are what would move.
     *
     * @param  list<array{duration_slots: int, room_types: list<string>}>  $meetings
     * @param  list<array<string, mixed>>  $courseRows  the course's current rows in this section
     * @return list<array{period: string, label: string, free_starts: int}>
     */
    public function courseOptions(
        int $sectionId,
        array $meetings,
        bool $isField,
        ?string $currentPeriod,
        array $courseRows = [],
    ): array {
        $excluded = [];
        foreach ($courseRows as $row) {
            $range = $this->slotRange($row);
            if ($range !== null) {
                $excluded[(string) ($row['day'] ?? '')][] = $range;
            }
        }

        $options = [];
        foreach (SchedulingPolicy::preferredPeriods() as $period) {
            if ($period === $currentPeriod) {
                continue;
            }

            $freeStarts = PHP_INT_MAX;
            foreach ($meetings as $meeting) {
                $freeStarts = min($freeStarts, $this->freeStarts($sectionId, $meeting, $period, $isField, $excluded));
            }

            // Every meeting of the course must find its own start time.
            if ($meetings === [] || $freeStarts < count($meetings)) {
                continue;
            }

            $options[] = ['period' => $period, 'label' => SchedulingPolicy::preferredPeriodLabel($period), 'free_starts' => $freeStarts];
        }

        return $this->ranked($options);
    }

    /**
     * Sessions a whole section could be pinned to, best first. Every meeting
     * must have a free start time, and the meetings must pack into the
     * section's days inside the window without overlapping.
     *
     * @param  list<array{duration_slots: int, room_types: list<string>, field: bool}>  $meetings
     * @return list<array{period: string, label: string, free_starts: int}>
     */
    public function sectionOptions(int $sectionId, array $meetings, ?string $currentPeriod): array
    {
        if ($meetings === []) {
            return [];
        }

        $options = [];
        foreach (SchedulingPolicy::preferredPeriods() as $period) {
            if ($period === $currentPeriod) {
                continue;
            }

            $freeStarts = 0;
            foreach ($meetings as $meeting) {
                $starts = $this->freeStarts($sectionId, $meeting, $period, (bool) $meeting['field']);
                if ($starts === 0) {
                    continue 2;
                }
                $freeStarts += $starts;
            }

            if (! $this->packs($sectionId, $meetings, $period)) {
                continue;
            }

            $options[] = ['period' => $period, 'label' => SchedulingPolicy::preferredPeriodLabel($period), 'free_starts' => $freeStarts];
        }

        return $this->ranked($options);
    }

    /**
     * @param  array{duration_slots: int, room_types: list<string>}  $meeting
     * @param  array<string, list<array{0: int, 1: int}>>  $excluded  ranges of the section's own rows to ignore
     */
    private function freeStarts(int $sectionId, array $meeting, string $period, bool $isField, array $excluded = []): int
    {
        $duration = (int) $meeting['duration_slots'];
        [$from, $to] = $this->window($period, $isField);
        if ($duration <= 0 || $to - $from < $duration) {
            return 0;
        }

        $roomIds = $this->candidateRoomIds($meeting['room_types']);
        $count = 0;
        foreach ($this->days($isField) as $day) {
            $sectionRanges = $this->without($this->sectionBusy[$sectionId][$day] ?? [], $excluded[$day] ?? []);

            foreach (SchedulingPolicy::generatedStartSlotsForDuration($duration) as $start) {
                $end = $start + $duration;
                if ($start < $from || $end > $to || $this->overlapsAny($sectionRanges, $start, $end)) {
                    continue;
                }

                if ($roomIds === null || $this->anyRoomFree($roomIds, $day, $start, $end)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Greedy first-fit of the section's meetings, longest first, into the
     * window on each day. Conservative: a packing it cannot find is not
     * offered, so a recommended session is one the section really fits.
     *
     * @param  list<array{duration_slots: int, room_types: list<string>, field: bool}>  $meetings
     */
    private function packs(int $sectionId, array $meetings, string $period): bool
    {
        usort($meetings, static fn (array $a, array $b): int => $b['duration_slots'] <=> $a['duration_slots']);

        $placed = [];
        foreach ($meetings as $meeting) {
            $duration = (int) $meeting['duration_slots'];
            [$from, $to] = $this->window($period, (bool) $meeting['field']);
            $fits = false;

            foreach ($this->days((bool) $meeting['field']) as $day) {
                $busy = [...($this->sectionBusy[$sectionId][$day] ?? []), ...($placed[$day] ?? [])];
                foreach (SchedulingPolicy::generatedStartSlotsForDuration($duration) as $start) {
                    $end = $start + $duration;
                    if ($start >= $from && $end <= $to && ! $this->overlapsAny($busy, $start, $end)) {
                        $placed[$day][] = [$start, $end];
                        $fits = true;
                        break 2;
                    }
                }
            }

            if (! $fits) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: int, 1: int} */
    private function window(string $period, bool $isField): array
    {
        [$from, $to] = SchedulingPolicy::preferredPeriodSlotRange($period);
        if ($isField) {
            $to = min($to, $this->toSlot(SchedulingPolicy::fieldDayEndTime()));
        }

        return [$from, $to];
    }

    /** @return list<string> */
    private function days(bool $isField): array
    {
        $days = $isField ? SchedulingPolicy::WEEKDAYS : SchedulingPolicy::WEEKDAYS_AND_SATURDAY;

        return $this->allowedDays === null ? $days : array_values(array_intersect($days, $this->allowedDays));
    }

    /**
     * Rooms a meeting could use, or null when it needs none (online or field).
     *
     * @param  list<string>  $roomTypes
     * @return list<int>|null
     */
    private function candidateRoomIds(array $roomTypes): ?array
    {
        if (in_array('online', $roomTypes, true) || in_array('field', $roomTypes, true)) {
            return null;
        }

        $ids = [];
        foreach ($this->roomsById as $room) {
            if (in_array((string) ($room['room_type'] ?? ''), $roomTypes, true)
                && in_array((string) ($room['status'] ?? 'available'), ['available', ''], true)) {
                $ids[] = (int) $room['id'];
            }
        }

        return $ids;
    }

    /** @param  list<int>  $roomIds */
    private function anyRoomFree(array $roomIds, string $day, int $start, int $end): bool
    {
        foreach ($roomIds as $roomId) {
            if (! $this->withinGrant($roomId, $day, $start, $end)) {
                continue;
            }
            if (! $this->overlapsAny($this->roomBusy[$roomId][$day] ?? [], $start, $end)) {
                return true;
            }
        }

        return false;
    }

    /** A borrowed room may only be used inside a window its owner granted. */
    private function withinGrant(int $roomId, string $day, int $start, int $end): bool
    {
        $windows = $this->roomsById[$roomId]['grant_windows'] ?? null;
        if (! is_array($windows)) {
            return true;
        }

        foreach ($windows as $window) {
            if ((string) ($window['day'] ?? '') === $day
                && $this->toSlot((string) $window['start_time']) <= $start
                && $this->toSlot((string) $window['end_time']) >= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{period: string, label: string, free_starts: int}>  $options
     * @return list<array{period: string, label: string, free_starts: int}>
     */
    private function ranked(array $options): array
    {
        usort($options, static fn (array $a, array $b): int => $b['free_starts'] <=> $a['free_starts']);

        return $options;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @param  list<array{0: int, 1: int}>  $remove
     * @return list<array{0: int, 1: int}>
     */
    private function without(array $ranges, array $remove): array
    {
        return $remove === [] ? $ranges : array_values(array_filter(
            $ranges,
            static fn (array $range): bool => ! in_array($range, $remove, true),
        ));
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function overlapsAny(array $ranges, int $start, int $end): bool
    {
        foreach ($ranges as [$busyStart, $busyEnd]) {
            if ($start < $busyEnd && $busyStart < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: int, 1: int}|null
     */
    private function slotRange(array $row): ?array
    {
        $start = (string) ($row['start_time'] ?? '');
        $end = (string) ($row['end_time'] ?? '');
        if ($start === '' || $end === '') {
            return null;
        }

        $range = [$this->toSlot($start), $this->toSlot($end)];

        return $range[1] > $range[0] ? $range : null;
    }

    private function toSlot(string $time): int
    {
        return intdiv(
            SchedulingPolicy::timeToMinutes($time) - SchedulingPolicy::timeToMinutes(SchedulingPolicy::openingTime()),
            SchedulingPolicy::SLOT_MINUTES,
        );
    }
}
