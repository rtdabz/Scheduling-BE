<?php

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Rooms;

class BatchConflictValidator
{
    /**
     * @param  array<int|string, array<string, mixed>>  $rows  candidate rows keyed by caller-facing index
     * @return list<BatchConflict>
     */
    public function validate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $index => $row) {
            $normalized[$index] = $this->normalizeRow($row);
        }

        return $this->pairwiseConflicts($normalized, $this->courseCodeMap($normalized), $this->roomTypes($normalized));
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
     * @param  array<int|string, array<string, mixed>>  $rows  already normalized
     * @param  array<int, string>  $courses
     * @param  array<int, string>  $roomTypes
     * @return list<BatchConflict>
     */
    private function pairwiseConflicts(array $rows, array $courses, array $roomTypes): array
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

                if ($left['semester_id'] !== $right['semester_id'] || $left['day'] !== $right['day']) {
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

                if (
                    $left['course_id'] > 0
                    && $left['course_id'] === $right['course_id']
                    && $left['section_id'] !== $right['section_id']
                    && $left['mode'] === 'online'
                    && $right['mode'] === 'online'
                ) {
                    $add(BatchConflict::RULE_SUBJECT_SECTION_TIME);
                }

                if ($this->sharesExclusiveRoom($left, $right, $roomTypes)) {
                    $add(BatchConflict::RULE_ROOM);
                }

                if (
                    $left['faculty_id'] !== null
                    && $left['faculty_id'] === $right['faculty_id']
                ) {
                    $add(BatchConflict::RULE_FACULTY);
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<int, string>  $roomTypes
     */
    private function sharesExclusiveRoom(array $left, array $right, array $roomTypes): bool
    {
        if ($left['room_id'] <= 0 || $left['room_id'] !== $right['room_id']) {
            return false;
        }

        if ($left['mode'] === 'online' || $right['mode'] === 'online') {
            return false;
        }

        return ! Rooms::isSharedType($roomTypes[$left['room_id']] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'semester_id' => (int) ($row['semester_id'] ?? 0),
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
     * @return array<int, string>
     */
    private function courseCodeMap(array $rows): array
    {
        $courseIds = $this->distinctInts($rows, 'course_id');

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
     * @param  array<int|string, array<string, mixed>>  $rows  already normalized
     * @return array<int, string>
     */
    private function roomTypes(array $rows): array
    {
        $roomIds = $this->distinctInts($rows, 'room_id');

        return $roomIds === []
            ? []
            : Rooms::query()
                ->whereIn('id', $roomIds)
                ->pluck('room_type', 'id')
                ->mapWithKeys(static fn ($type, $id): array => [(int) $id => (string) $type])
                ->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<int>
     */
    private function distinctInts(array $rows, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row[$key] ?? 0),
            $rows,
        ))));
    }
}
