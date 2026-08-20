<?php

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Rooms;
use App\Models\Schedule;
use Illuminate\Support\Collection;

/**
 * Conflict rules for a set of candidate schedule rows saved together.
 *
 * Extracted from ScheduleController::checkIntraBatchConflicts and
 * ScheduleRecommendationController::validateBatchConflicts, which were
 * near-line-by-line twins that had already drifted: only the batch path checked
 * `subject_section_time_conflict`, and only the batch path honoured ignored
 * schedule ids, so accepting a recommendation skipped a rule the equivalent
 * batch save enforced and counted rows it was about to delete against capacity.
 *
 * RuleEngine validates one row against persisted rows. This validates the
 * candidate set against itself *and* against persisted rows for the shared
 * capacity rules, which RuleEngine cannot see.
 */
class BatchConflictValidator
{
    public function __construct(
        private readonly DepartmentResourceSlotLimitService $resourceLimits,
    ) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $rows  candidate rows keyed by caller-facing index
     * @param  list<int>  $ignoreScheduleIds  persisted schedules being replaced by this write
     * @return list<BatchConflict>
     */
    public function validate(array $rows, array $ignoreScheduleIds = []): array
    {
        if ($rows === []) {
            return [];
        }

        // Normalize once. The pairwise pass is O(n²) over indexes, so parsing
        // times inside the loops would parse each row n times.
        $normalized = [];
        foreach ($rows as $index => $row) {
            $normalized[$index] = $this->normalizeRow($row);
        }

        $courses = $this->courseCodeMap($normalized);
        $rooms = $this->rooms($normalized);
        $roomTypes = $rooms
            ->mapWithKeys(static fn (Rooms $room): array => [(int) $room->id => (string) $room->room_type])
            ->all();
        $roomCapacities = $rooms
            ->mapWithKeys(static fn (Rooms $room): array => [
                (int) $room->id => max(1, (int) ($room->max_concurrent_classes ?? 1)),
            ])
            ->all();
        $scope = $this->groupScope($normalized);

        return array_values(array_merge(
            $this->pairwiseConflicts($normalized, $courses, $roomTypes, $roomCapacities),
            $this->roomCapacityConflicts($normalized, $courses, $roomTypes, $roomCapacities, $scope, $ignoreScheduleIds),
            $this->onlineCapacityConflicts($normalized, $courses, $scope, $ignoreScheduleIds),
        ));
    }

    public function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return ($hour * 60) + $minute;
    }

    public function minutesToTimeString(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Pairwise rules: two candidate rows that overlap in the same term and day.
     *
     * @param  array<int|string, array<string, mixed>>  $rows  already normalized
     * @param  array<int, string>  $courses
     * @param  array<int, string>  $roomTypes
     * @param  array<int, int>  $roomCapacities
     * @return list<BatchConflict>
     */
    private function pairwiseConflicts(array $rows, array $courses, array $roomTypes, array $roomCapacities): array
    {
        $conflicts = [];
        $indexes = array_keys($rows);
        $count = count($indexes);

        for ($i = 0; $i < $count; $i++) {
            $leftIndex = $indexes[$i];
            $left = $rows[$leftIndex];

            for ($j = $i + 1; $j < $count; $j++) {
                $rightIndex = $indexes[$j];
                $right = $rows[$rightIndex];

                if ($left['term_id'] !== $right['term_id'] || $left['day'] !== $right['day']) {
                    continue;
                }

                if ($left['start'] >= $right['end'] || $right['start'] >= $left['end']) {
                    continue;
                }

                $overlapStart = $this->minutesToTimeString(max($left['start'], $right['start']));
                $overlapEnd = $this->minutesToTimeString(min($left['end'], $right['end']));
                $leftCode = $courses[$left['course_id']] ?? 'Course';
                $rightCode = $courses[$right['course_id']] ?? 'Course';

                $add = static function (string $rule) use (
                    &$conflicts, $rightIndex, $leftIndex, $rightCode, $leftCode, $right, $overlapStart, $overlapEnd
                ): void {
                    $conflicts[] = new BatchConflict(
                        rule: $rule,
                        index: $rightIndex,
                        otherIndex: $leftIndex,
                        courseCode: $rightCode,
                        otherCourseCode: $leftCode,
                        day: $right['day'],
                        overlapStart: $overlapStart,
                        overlapEnd: $overlapEnd,
                    );
                };

                if ($left['section_id'] > 0 && $left['section_id'] === $right['section_id']) {
                    $add(BatchConflict::RULE_SECTION);
                }

                // The same course cannot run online for two different sections at
                // once — one online session cannot serve both.
                if (
                    $left['course_id'] > 0
                    && $left['course_id'] === $right['course_id']
                    && $left['section_id'] !== $right['section_id']
                    && $left['mode'] === 'online'
                    && $right['mode'] === 'online'
                ) {
                    $add(BatchConflict::RULE_SUBJECT_SECTION_TIME);
                }

                if ($this->sharesExclusiveRoom($left, $right, $roomTypes, $roomCapacities)) {
                    $add(BatchConflict::RULE_ROOM);
                }

                if ($left['faculty_id'] !== null && $left['faculty_id'] === $right['faculty_id']) {
                    $add(BatchConflict::RULE_FACULTY);
                }
            }
        }

        return $conflicts;
    }

    /**
     * True when both rows occupy the same physical room and that room admits
     * only one class at a time. Rooms with a configured concurrent limit above
     * one are handled by the capacity sweep instead.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<int, string>  $roomTypes
     * @param  array<int, int>  $roomCapacities
     */
    private function sharesExclusiveRoom(array $left, array $right, array $roomTypes, array $roomCapacities): bool
    {
        if ($left['room_id'] <= 0 || $left['room_id'] !== $right['room_id']) {
            return false;
        }

        if ($left['mode'] === 'online' || $right['mode'] === 'online') {
            return false;
        }

        $isFieldRoom = ($roomTypes[$left['room_id']] ?? null) === 'field';

        // A shared FIELD room is scoped per department: two departments using it
        // at once is governed by each department's own limit, not by collision.
        if ($isFieldRoom && $left['department_id'] !== $right['department_id']) {
            return false;
        }

        $capacity = $isFieldRoom
            ? $this->resourceLimits->field($left['department_id'])
            : ($roomCapacities[$left['room_id']] ?? 1);

        return $capacity <= 1;
    }

    /**
     * Sweep-line over each (term, department, room, day) group for rooms whose
     * concurrent limit is above one, counting candidate rows together with the
     * persisted rows they will sit alongside.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @param  array<int, string>  $courses
     * @param  array<int, string>  $roomTypes
     * @param  array<int, int>  $roomCapacities
     * @param  list<int>  $ignoreScheduleIds
     * @return list<BatchConflict>
     */
    private function roomCapacityConflicts(
        array $rows,
        array $courses,
        array $roomTypes,
        array $roomCapacities,
        array $scope,
        array $ignoreScheduleIds,
    ): array {
        $groups = [];

        foreach ($rows as $index => $row) {
            if ($row['room_id'] <= 0 || $row['term_id'] <= 0 || $row['department_id'] <= 0) {
                continue;
            }
            if ($row['day'] === '' || $row['mode'] === 'online' || $row['start'] >= $row['end']) {
                continue;
            }

            $capacity = $this->roomCapacityFor($row['room_id'], $row['department_id'], $roomTypes, $roomCapacities);
            if ($capacity <= 1) {
                continue;
            }

            $groups["{$row['term_id']}:{$row['department_id']}:{$row['room_id']}:{$row['day']}"][] = [
                'index' => $index,
                'capacity' => $capacity,
                'day' => $row['day'],
                'start' => $row['start'],
                'end' => $row['end'],
                'course_id' => $row['course_id'],
            ];
        }

        if ($groups === []) {
            return [];
        }

        $persisted = Schedule::query()
            ->whereIn('room_id', $scope['room_ids'])
            ->whereIn('term_id', $scope['term_ids'])
            ->whereIn('department_id', $scope['department_ids'])
            ->whereIn('day', $scope['days'])
            ->when($ignoreScheduleIds !== [], fn ($query) => $query->whereNotIn('id', $ignoreScheduleIds))
            ->get(['id', 'room_id', 'term_id', 'department_id', 'day', 'start_time', 'end_time']);

        foreach ($persisted as $schedule) {
            $roomId = (int) $schedule->room_id;
            $departmentId = (int) $schedule->department_id;
            $capacity = $this->roomCapacityFor($roomId, $departmentId, $roomTypes, $roomCapacities);
            if ($capacity <= 1) {
                continue;
            }

            $groups["{$schedule->term_id}:{$departmentId}:{$roomId}:{$schedule->day}"][] = [
                'index' => null,
                'schedule_id' => (int) $schedule->id,
                'capacity' => $capacity,
                'day' => (string) $schedule->day,
                'start' => $this->timeToMinutes((string) $schedule->start_time),
                'end' => $this->timeToMinutes((string) $schedule->end_time),
                'course_id' => 0,
            ];
        }

        return $this->sweepCapacityGroups(
            $groups,
            $courses,
            BatchConflict::RULE_ROOM_CAPACITY,
            fn (array $item): int => (int) $item['capacity'],
            withOverlapWindow: true,
        );
    }

    /**
     * Sweep-line over each (term, department, day) group of online rows against
     * the department's configured online slot limit.
     *
     * @param  array<int|string, array<string, mixed>>  $rows  already normalized
     * @param  array<int, string>  $courses
     * @param  array{room_ids: list<int>, term_ids: list<int>, department_ids: list<int>, days: list<string>}  $scope
     * @param  list<int>  $ignoreScheduleIds
     * @return list<BatchConflict>
     */
    private function onlineCapacityConflicts(array $rows, array $courses, array $scope, array $ignoreScheduleIds): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            if ($row['mode'] !== 'online') {
                continue;
            }
            if ($row['term_id'] <= 0 || $row['department_id'] <= 0 || $row['day'] === '') {
                continue;
            }

            $groups["{$row['term_id']}:{$row['department_id']}:{$row['day']}"][] = [
                'index' => $index,
                'department_id' => $row['department_id'],
                'day' => $row['day'],
                'start' => $row['start'],
                'end' => $row['end'],
                'course_id' => $row['course_id'],
            ];
        }

        if ($groups === []) {
            return [];
        }

        $persisted = Schedule::query()
            ->where('mode', 'online')
            ->whereIn('term_id', $scope['term_ids'])
            ->whereIn('department_id', $scope['department_ids'])
            ->whereIn('day', $scope['days'])
            ->when($ignoreScheduleIds !== [], fn ($query) => $query->whereNotIn('id', $ignoreScheduleIds))
            ->get(['id', 'term_id', 'department_id', 'day', 'start_time', 'end_time']);

        foreach ($persisted as $schedule) {
            $groups["{$schedule->term_id}:{$schedule->department_id}:{$schedule->day}"][] = [
                'index' => null,
                'schedule_id' => (int) $schedule->id,
                'department_id' => (int) $schedule->department_id,
                'day' => (string) $schedule->day,
                'start' => $this->timeToMinutes((string) $schedule->start_time),
                'end' => $this->timeToMinutes((string) $schedule->end_time),
                'course_id' => 0,
            ];
        }

        return $this->sweepCapacityGroups(
            $groups,
            $courses,
            BatchConflict::RULE_ONLINE_CAPACITY,
            fn (array $item): int => $this->resourceLimits->online((int) ($item['department_id'] ?? 0)),
            withOverlapWindow: false,
        );
    }

    /**
     * Shared sweep-line: walk start/end events in time order and report the
     * candidate row that pushes concurrency past the limit. Each candidate is
     * reported at most once per group.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  array<int, string>  $courses
     * @param  callable(array<string, mixed>): int  $capacityResolver
     * @return list<BatchConflict>
     */
    private function sweepCapacityGroups(
        array $groups,
        array $courses,
        string $rule,
        callable $capacityResolver,
        bool $withOverlapWindow,
    ): array {
        $conflicts = [];

        foreach ($groups as $items) {
            $events = [];
            foreach ($items as $item) {
                $events[] = ['minute' => $item['start'], 'delta' => 1, 'item' => $item];
                $events[] = ['minute' => $item['end'], 'delta' => -1, 'item' => $item];
            }

            usort(
                $events,
                static fn (array $left, array $right): int => ($left['minute'] <=> $right['minute']) ?: ($left['delta'] <=> $right['delta']),
            );

            $active = [];
            $reported = [];
            foreach ($events as $event) {
                $item = $event['item'];
                $activeKey = $item['index'] ?? "existing:{$item['schedule_id']}";

                if ($event['delta'] < 0) {
                    unset($active[$activeKey]);

                    continue;
                }

                $active[$activeKey] = $item;
                $capacity = $capacityResolver($item);

                if ($item['index'] === null || count($active) <= $capacity || isset($reported[$item['index']])) {
                    continue;
                }

                $conflicts[] = new BatchConflict(
                    rule: $rule,
                    index: $item['index'],
                    courseCode: $courses[$item['course_id']] ?? 'Course',
                    day: $item['day'],
                    overlapStart: $withOverlapWindow
                        ? $this->minutesToTimeString(max(array_column($active, 'start')))
                        : null,
                    overlapEnd: $withOverlapWindow
                        ? $this->minutesToTimeString(min(array_column($active, 'end')))
                        : null,
                    capacity: $capacity,
                );
                $reported[$item['index']] = true;
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'term_id' => (int) ($row['term_id'] ?? 0),
            'section_id' => (int) ($row['section_id'] ?? 0),
            'department_id' => (int) ($row['department_id'] ?? 0),
            'room_id' => (int) ($row['room_id'] ?? 0),
            'course_id' => (int) ($row['course_id'] ?? $row['subject_id'] ?? 0),
            'faculty_id' => empty($row['faculty_id']) ? null : (int) $row['faculty_id'],
            'day' => (string) ($row['day'] ?? ''),
            'mode' => (string) ($row['mode'] ?? 'on-site'),
            'start' => $this->timeToMinutes((string) ($row['start_time'] ?? '00:00')),
            'end' => $this->timeToMinutes((string) ($row['end_time'] ?? '00:00')),
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows  already normalized
     * @return array{room_ids: list<int>, term_ids: list<int>, department_ids: list<int>, days: list<string>}
     */
    private function groupScope(array $rows): array
    {
        $roomIds = [];
        $termIds = [];
        $departmentIds = [];
        $days = [];

        foreach ($rows as $row) {
            if ($row['room_id'] > 0) {
                $roomIds[$row['room_id']] = $row['room_id'];
            }
            if ($row['term_id'] > 0) {
                $termIds[$row['term_id']] = $row['term_id'];
            }
            if ($row['department_id'] > 0) {
                $departmentIds[$row['department_id']] = $row['department_id'];
            }
            if ($row['day'] !== '') {
                $days[$row['day']] = $row['day'];
            }
        }

        return [
            'room_ids' => array_values($roomIds),
            'term_ids' => array_values($termIds),
            'department_ids' => array_values($departmentIds),
            'days' => array_values($days),
        ];
    }

    /**
     * @param  array<int, string>  $roomTypes
     * @param  array<int, int>  $roomCapacities
     */
    private function roomCapacityFor(int $roomId, int $departmentId, array $roomTypes, array $roomCapacities): int
    {
        return ($roomTypes[$roomId] ?? null) === 'field'
            ? $this->resourceLimits->field($departmentId)
            : ($roomCapacities[$roomId] ?? 1);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows  already normalized
     * @return array<int, string>
     */
    private function courseCodeMap(array $rows): array
    {
        $courseIds = $this->distinctInts($rows, static fn (array $row) => $row['course_id'] ?? 0);

        if ($courseIds === []) {
            return [];
        }

        return Course::query()
            ->whereIn('id', $courseIds)
            ->pluck('course_code', 'id')
            ->mapWithKeys(static fn ($code, $id): array => [(int) $id => (string) $code])
            ->all();
    }

    /**
     * Room type and capacity are read once per validate() call and shared by the
     * pairwise and capacity passes; the two controllers used to query the rooms
     * table twice for the same ids.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return Collection<int, Rooms>
     */
    private function rooms(array $rows): Collection
    {
        $roomIds = $this->distinctInts($rows, static fn (array $row) => $row['room_id'] ?? 0);

        return $roomIds === []
            ? collect()
            : Rooms::query()->whereIn('id', $roomIds)->get(['id', 'room_type', 'max_concurrent_classes']);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): mixed  $extractor
     * @return list<int>
     */
    private function distinctInts(array $rows, callable $extractor): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) $extractor($row),
            $rows,
        ))));
    }
}
