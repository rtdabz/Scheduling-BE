<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Constraints;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\SchedulingPolicy;

final class SchedulingConstraintKernel
{
    /**
     * Explicit order for the constraint families migrated in Phase 3.
     * Lower values are reported first.
     */
    public const RULE_PRIORITY = [
        'hybrid_mode' => 100,
        'hybrid_eligibility' => 110,
        'hybrid_component_type' => 120,
        'hybrid_component_shape' => 130,
        'nstp_day_constraint' => 200,
        'field_day_constraint' => 210,
        'minor_day_constraint' => 220,
        'major_sunday_mode_constraint' => 230,
        'field_evening_window' => 240,
        'forced_course_day' => 250,
        'delivery_room_alignment' => 290,
        'room_type_match' => 300,
        'section_conflict' => 400,
        'subject_section_time_conflict' => 410,
        'faculty_conflict' => 420,
        'room_conflict' => 430,
        'room_capacity_conflict' => 440,
        'online_capacity_conflict' => 450,
        'hybrid_component_count' => 500,
        'hybrid_components' => 510,
        'minor_split_component_count' => 520,
        'minor_split_eligibility' => 530,
        'minor_split_pattern' => 540,
        'minor_split_duration' => 550,
        'split_group_day_separation' => 560,
    ];

    /**
     * @param  list<ScheduleRow>  $candidateRows
     * @param  list<int>  $ignoreScheduleIds
     * @return list<ConstraintViolation>
     */
    public function evaluateRow(
        ScheduleRow $row,
        SchedulingSnapshot $snapshot,
        array $candidateRows = [],
        array $ignoreScheduleIds = [],
    ): array {
        $course = $snapshot->coursesById[$row->courseId] ?? null;
        if (! is_array($course)) {
            return [];
        }

        $violations = [
            ...$this->hybridRowViolations($row, $course, $snapshot),
            ...$this->dayViolations($row, $course, $snapshot),
            ...$this->deliveryAlignmentViolations($row, $course, $snapshot),
            ...$this->roomModeViolations($row, $course, $snapshot),
        ];

        $persisted = array_values(array_filter(
            $snapshot->persistedSchedules,
            static fn (array $schedule): bool => ! in_array((int) ($schedule['id'] ?? 0), $ignoreScheduleIds, true),
        ));
        $others = [...$persisted, ...$candidateRows];

        $violations = [
            ...$violations,
            ...$this->overlapViolations($row, $others),
            ...$this->capacityViolations($row, $others, $snapshot),
        ];

        return $this->sortViolations($violations);
    }

    /** @return list<ConstraintViolation> */
    public function evaluateMeetingGroup(MeetingGroup $group, SchedulingSnapshot $snapshot): array
    {
        $course = $snapshot->coursesById[$group->courseId] ?? null;
        if (! is_array($course)) {
            return [];
        }

        $violations = [];
        $rowCount = count($group->rows);

        if ($group->type === 'hybrid') {
            if ($rowCount !== 2) {
                $violations[] = $this->violation(
                    'hybrid_component_count',
                    'Hybrid scheduling requires exactly one online lecture and one on-site laboratory meeting.',
                    'meeting_group',
                    ['split_group_id' => $group->groupId],
                );
            } elseif ($this->meetingTypes($group->rows) !== ['laboratory', 'lecture']) {
                $violations[] = $this->violation(
                    'hybrid_components',
                    'Hybrid scheduling requires one lecture component and one laboratory component.',
                    'meeting_group',
                    ['split_group_id' => $group->groupId],
                );
            }
        }

        if ($group->type === 'minor_split') {
            if ($rowCount !== 2) {
                $violations[] = $this->violation(
                    'minor_split_component_count',
                    'Split Session scheduling requires exactly two linked meetings.',
                    'meeting_group',
                    ['split_group_id' => $group->groupId],
                );
            } else {
                if (! (bool) ($snapshot->departmentSettings['gec_split_schedule_override_enabled'] ?? false)
                    || SchedulingConstraintPredicates::isMajorCourse($course)) {
                    $violations[] = $this->violation(
                        'minor_split_eligibility',
                        'Split Session is available only for minor courses when Minor Course Split Sessions is enabled.',
                        'meeting_group',
                        ['split_group_id' => $group->groupId],
                    );
                }

                $this->appendMinorSplitShapeViolations($violations, $group, $course);
            }
        }

        if ($rowCount > 1 && count(array_unique(array_map(static fn (ScheduleRow $row): string => $row->day, $group->rows))) !== $rowCount) {
            $violations[] = $this->violation(
                'split_group_day_separation',
                'Split meetings for the same course must be scheduled on different days.',
                'meeting_group',
                ['split_group_id' => $group->groupId],
            );
        }

        return $this->sortViolations($violations);
    }

    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    private function hybridRowViolations(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        if ($row->mode === 'field' && $row->isHybrid) {
            return [$this->violation('hybrid_mode', 'Field schedules cannot be marked as hybrid.')];
        }

        if (! $row->isHybrid) {
            return [];
        }

        if (! (bool) ($snapshot->departmentSettings['lecture_lab_schedule_override_enabled'] ?? false)
            || ! SchedulingConstraintPredicates::isMajorCourse($course)
            || (int) ($course['lecture_hours'] ?? 0) <= 0
            || (int) ($course['lab_hours'] ?? 0) <= 0) {
            return [$this->violation(
                'hybrid_eligibility',
                'Hybrid scheduling is available only for major courses with both lecture and laboratory hours when the department setting is enabled.',
            )];
        }

        $expected = match ($row->meetingType) {
            'lecture' => ['mode' => 'online', 'minutes' => (int) $course['lecture_hours'] * 60],
            'laboratory' => ['mode' => 'on-site', 'minutes' => (int) $course['lab_hours'] * 180],
            default => null,
        };
        if ($expected === null) {
            return [$this->violation('hybrid_component_type', 'Hybrid schedules must identify each meeting as lecture or laboratory.')];
        }

        $duration = SchedulingPolicy::timeToMinutes($row->endTime) - SchedulingPolicy::timeToMinutes($row->startTime);
        if ($row->mode !== $expected['mode'] || $duration !== $expected['minutes']) {
            return [$this->violation(
                'hybrid_component_shape',
                $row->meetingType === 'lecture'
                    ? 'The Hybrid lecture must be online and use the Generator lecture duration.'
                    : 'The Hybrid laboratory must be on-site and use the Generator laboratory duration.',
            )];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    private function dayViolations(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $violations = [];
        $isField = SchedulingConstraintPredicates::isFieldCourse($course, $snapshot->fieldCourseCodes);

        if (! SchedulingConstraintPredicates::isNstpCourse($course)) {
            if ($isField && ! in_array($row->day, SchedulingPolicy::WEEKDAYS, true)) {
                $violations[] = $this->violation('field_day_constraint', 'PATHFIT and other field courses must be scheduled Monday through Friday.');
            } elseif (! $isField && ! SchedulingConstraintPredicates::isMajorCourse($course)
                && ! in_array($row->day, SchedulingPolicy::WEEKDAYS_AND_SATURDAY, true)) {
                $violations[] = $this->violation('minor_day_constraint', 'Minor courses (GEC, GEE, and similar) must be scheduled Monday through Saturday.');
            } elseif (! $isField && SchedulingConstraintPredicates::isMajorCourse($course)
                && (bool) ($snapshot->departmentSettings['sunday_online_only_enabled'] ?? true)
                && $row->day === 'Sunday' && $row->mode !== 'online') {
                $violations[] = $this->violation('major_sunday_mode_constraint', 'Major courses scheduled on Sunday must use online delivery mode.');
            }
        }

        if (($row->mode === 'field' || $isField)
            && ! (bool) ($snapshot->departmentSettings['field_evening_schedule_enabled'] ?? false)
            && SchedulingPolicy::timeToMinutes($row->endTime) > SchedulingPolicy::timeToMinutes(SchedulingPolicy::FIELD_DAY_END_TIME)) {
            $violations[] = $this->violation('field_evening_window', 'Field courses must end by 5:00 PM unless evening field scheduling is enabled for this department.');
        }

        $forcedDay = $snapshot->forcedDaysByCourseId[$row->courseId] ?? null;
        if (is_string($forcedDay) && $forcedDay !== $row->day) {
            $violations[] = $this->violation(
                'forced_course_day',
                "This course is configured to meet on {$forcedDay}.",
                context: ['required_day' => $forcedDay],
            );
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    private function roomModeViolations(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $room = $row->roomId === null ? null : ($snapshot->roomsById[$row->roomId] ?? null);
        $requiredType = SchedulingConstraintPredicates::effectiveRoomType($course, $snapshot->fieldCourseCodes, $row->meetingType);

        if ($row->mode === 'online') {
            return SchedulingConstraintPredicates::allowsOnline($course, $snapshot->fieldCourseCodes, $row->meetingType)
                ? []
                : [$this->violation('room_type_match', 'This course component cannot use online delivery for its room requirement.')];
        }

        if (! is_array($room)) {
            return $row->mode === 'on-site'
                && SchedulingConstraintPredicates::allowsRoomTba($course, $snapshot->fieldCourseCodes, $row->meetingType)
                    ? []
                    : [$this->violation('room_type_match', 'A physical room is required for this schedule.')];
        }

        $roomType = (string) ($room['room_type'] ?? '');
        if ($row->mode === 'field') {
            return $roomType === 'field'
                ? []
                : [$this->violation('room_type_match', 'Field schedules must use a field room assignment.')];
        }

        if (in_array($roomType, ['online', 'field'], true)) {
            return [$this->violation('room_type_match', 'On-site schedules require a physical lecture or laboratory room.')];
        }

        if ($requiredType === 'laboratory' && $roomType !== 'laboratory') {
            return [$this->violation('room_type_match', 'This course component requires a laboratory room.')];
        }

        if ($requiredType === 'lecture' && $roomType === 'laboratory'
            && ! SchedulingConstraintPredicates::canUseLaboratoryForLecture($course, $room)) {
            return [$this->violation('room_type_match', 'This course can only use lecture-capable laboratory rooms as a fallback.')];
        }

        if ($requiredType === 'lecture' && in_array($roomType, ['lecture', 'laboratory'], true)) {
            return [];
        }

        return $requiredType === $roomType
            ? []
            : [$this->violation('room_type_match', "This course requires a {$requiredType} room.")];
    }

    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    private function deliveryAlignmentViolations(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        $room = $row->roomId === null ? null : ($snapshot->roomsById[$row->roomId] ?? null);
        $roomType = is_array($room) ? (string) ($room['room_type'] ?? '') : null;

        if ($row->mode === 'field' && $roomType !== 'field') {
            return [$this->violation('delivery_room_alignment', 'Field schedules must use a field room assignment.')];
        }

        $allowsLabTba = SchedulingConstraintPredicates::allowsRoomTba(
            $course,
            $snapshot->fieldCourseCodes,
            $row->meetingType,
        );
        if ($row->mode === 'on-site'
            && ((! is_array($room) && ! $allowsLabTba) || in_array($roomType, ['online', 'field'], true))) {
            return [$this->violation('delivery_room_alignment', 'On-site schedules must use a lecture or laboratory room assignment.')];
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @return list<ConstraintViolation>
     */
    private function overlapViolations(ScheduleRow $row, array $others): array
    {
        $violations = [];

        foreach ($others as $other) {
            if (! $this->sameTerm($row, $other) || ! SchedulingConstraintPredicates::rowOverlaps($row, $other)) {
                continue;
            }

            $context = $this->conflictContext($other);
            if ($row->sectionId === $this->intValue($other, 'section_id')) {
                $violations['section_conflict'] ??= $this->violation('section_conflict', 'Section already has an overlapping class.', context: $context);
            }

            $otherFacultyId = $this->nullableIntValue($other, 'faculty_id');
            if ($row->facultyId !== null && $row->facultyId === $otherFacultyId) {
                $violations['faculty_conflict'] ??= $this->violation('faculty_conflict', 'Faculty is already teaching an overlapping class.', context: $context);
            }

            if ($row->mode === 'online'
                && $this->stringValue($other, 'mode') === 'online'
                && $row->courseId === $this->intValue($other, 'course_id')
                && $row->sectionId !== $this->intValue($other, 'section_id')) {
                $violations['subject_section_time_conflict'] ??= $this->violation(
                    'subject_section_time_conflict',
                    'The same online course is already scheduled for another section at this time.',
                    context: $context,
                );
            }
        }

        return array_values($violations);
    }

    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @return list<ConstraintViolation>
     */
    private function capacityViolations(ScheduleRow $row, array $others, SchedulingSnapshot $snapshot): array
    {
        $violations = [];

        if ($row->mode === 'online') {
            $matches = $this->matchingRows($row, $others, static fn (ScheduleRow $target, array|ScheduleRow $other): bool => $target->departmentId === self::intValueStatic($other, 'department_id')
                && self::stringValueStatic($other, 'mode') === 'online');
            $capacity = max(1, (int) ($snapshot->resourceLimits['online'] ?? 1));
            if (SchedulingConstraintPredicates::concurrencyExceeds($row, $matches, $capacity)) {
                $violations[] = $this->violation('online_capacity_conflict', 'Online capacity is full for this department and time window.', context: ['capacity' => $capacity]);
            }

            return $violations;
        }

        if ($row->roomId === null || ! isset($snapshot->roomsById[$row->roomId])) {
            return [];
        }

        $room = $snapshot->roomsById[$row->roomId];
        $roomType = (string) ($room['room_type'] ?? '');
        $capacity = $roomType === 'field'
            ? max(1, (int) ($snapshot->resourceLimits['field'] ?? 1))
            : max(1, (int) ($room['max_concurrent_classes'] ?? 1));
        $departmentScoped = $roomType === 'field' || $capacity > 1;

        $matches = $this->matchingRows($row, $others, static fn (ScheduleRow $target, array|ScheduleRow $other): bool => $target->roomId === self::nullableIntValueStatic($other, 'room_id')
            && self::stringValueStatic($other, 'mode') !== 'online'
            && (! $departmentScoped || $target->departmentId === self::intValueStatic($other, 'department_id')));

        if ($capacity <= 1 && $matches !== []) {
            $violations[] = $this->violation('room_conflict', 'Room is already booked for an overlapping class.', context: $this->conflictContext($matches[0]));
        } elseif ($matches !== [] && SchedulingConstraintPredicates::concurrencyExceeds($row, $matches, $capacity)) {
            $violations[] = $this->violation('room_capacity_conflict', 'Room capacity is full for this department and time window.', context: ['capacity' => $capacity]);
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>|ScheduleRow>  $others
     * @param  callable(ScheduleRow, array<string, mixed>|ScheduleRow): bool  $predicate
     * @return list<array<string, mixed>|ScheduleRow>
     */
    private function matchingRows(ScheduleRow $row, array $others, callable $predicate): array
    {
        return array_values(array_filter(
            $others,
            fn (array|ScheduleRow $other): bool => $this->sameTerm($row, $other)
                && SchedulingConstraintPredicates::rowOverlaps($row, $other)
                && $predicate($row, $other),
        ));
    }

    /**
     * @param  list<ConstraintViolation>  $violations
     * @param  array<string, mixed>  $course
     */
    private function appendMinorSplitShapeViolations(array &$violations, MeetingGroup $group, array $course): void
    {
        $pattern = SchedulingPolicy::normalizePreferredPattern($group->rows[0]->preferredPattern);
        if (in_array($pattern, ['MW', 'TTh'], true)) {
            $expectedDays = $pattern === 'MW' ? ['Monday', 'Wednesday'] : ['Thursday', 'Tuesday'];
            $actualDays = array_map(static fn (ScheduleRow $row): string => $row->day, $group->rows);
            sort($actualDays);
            sort($expectedDays);
            if ($actualDays !== $expectedDays) {
                $violations[] = $this->violation('minor_split_pattern', "Split Session {$pattern} meetings must use the configured day pair.", 'meeting_group', ['split_group_id' => $group->groupId]);
            }
        }

        $totalMinutes = array_sum(array_map(
            static fn (ScheduleRow $row): int => SchedulingPolicy::timeToMinutes($row->endTime) - SchedulingPolicy::timeToMinutes($row->startTime),
            $group->rows,
        ));
        $expectedMinutes = max(1, (int) round((float) ($course['units'] ?? 0) * 60));
        if ($totalMinutes !== $expectedMinutes) {
            $violations[] = $this->violation(
                'minor_split_duration',
                'Split Session meeting durations must add up to the course contact hours used by the Schedule Generator.',
                'meeting_group',
                ['split_group_id' => $group->groupId],
            );
        }
    }

    /** @param list<ScheduleRow> $rows */
    private function meetingTypes(array $rows): array
    {
        $types = array_map(static fn (ScheduleRow $row): ?string => $row->meetingType, $rows);
        sort($types);

        return $types;
    }

    /** @return list<ConstraintViolation> */
    private function sortViolations(array $violations): array
    {
        $indexed = array_map(static fn (ConstraintViolation $violation, int $index): array => [$violation, $index], $violations, array_keys($violations));
        usort($indexed, static fn (array $left, array $right): int => (self::RULE_PRIORITY[$left[0]->ruleId] ?? PHP_INT_MAX) <=> (self::RULE_PRIORITY[$right[0]->ruleId] ?? PHP_INT_MAX)
            ?: $left[1] <=> $right[1]);

        return array_map(static fn (array $item): ConstraintViolation => $item[0], $indexed);
    }

    /** @param array<string, mixed> $context */
    private function violation(string $ruleId, string $message, string $scope = 'schedule_row', array $context = []): ConstraintViolation
    {
        $severity = (string) (SchedulingPolicy::CONSTRAINT_CATALOG[$ruleId]['severity'] ?? 'hard');

        return new ConstraintViolation($ruleId, $message, $severity, $scope, $context);
    }

    /** @param array<string, mixed>|ScheduleRow $other */
    private function sameTerm(ScheduleRow $row, array|ScheduleRow $other): bool
    {
        return $row->termId === $this->intValue($other, 'term_id');
    }

    /** @param array<string, mixed>|ScheduleRow $other */
    private function conflictContext(array|ScheduleRow $other): array
    {
        $id = $this->nullableIntValue($other, 'id');

        return $id === null ? [] : ['conflicting_schedule_id' => $id];
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private function intValue(array|ScheduleRow $row, string $key): int
    {
        return self::intValueStatic($row, $key);
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private static function intValueStatic(array|ScheduleRow $row, string $key): int
    {
        if ($row instanceof ScheduleRow) {
            return match ($key) {
                'term_id' => $row->termId,
                'section_id' => $row->sectionId,
                'course_id' => $row->courseId,
                'department_id' => $row->departmentId,
                default => 0,
            };
        }

        return (int) ($row[$key] ?? 0);
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private function nullableIntValue(array|ScheduleRow $row, string $key): ?int
    {
        return self::nullableIntValueStatic($row, $key);
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private static function nullableIntValueStatic(array|ScheduleRow $row, string $key): ?int
    {
        if ($row instanceof ScheduleRow) {
            return match ($key) {
                'faculty_id' => $row->facultyId,
                'room_id' => $row->roomId,
                default => null,
            };
        }

        $value = $row[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private function stringValue(array|ScheduleRow $row, string $key): string
    {
        return self::stringValueStatic($row, $key);
    }

    /** @param array<string, mixed>|ScheduleRow $row */
    private static function stringValueStatic(array|ScheduleRow $row, string $key): string
    {
        if ($row instanceof ScheduleRow) {
            return match ($key) {
                'mode' => $row->mode,
                default => '',
            };
        }

        return (string) ($row[$key] ?? '');
    }
}
