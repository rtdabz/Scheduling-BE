<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\Families\DeliveryModeConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\InstructorAvailabilityConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\MeetingDayConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\MeetingGroupConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\OperatingHoursConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\OverlapConflict;
use App\Services\Scheduling\Engine\Constraints\Families\RoomAvailabilityConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\RoomTypeConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\SectionLoadConstraints;

/**
 * Runs the constraint families against a snapshot and reports their violations
 * in RULE_PRIORITY order. The rules themselves live in Families/, one class per
 * family, named after the Engine/Rules class each mirrors (DeliveryModeRule ->
 * DeliveryModeConstraints), so each can be read and tested on its own; this
 * class only decides which families apply and in what order they are reported.
 */
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
        'slot_grid' => 201,
        'operating_hours' => 202,
        'preferred_pattern' => 205,
        'field_evening_window' => 240,
        'forced_course_day' => 250,
        'room_type_match' => 300,
        'room_availability' => 310,
        'room_department_alignment' => 320,
        'section_conflict' => 400,
        'subject_section_time_conflict' => 410,
        'faculty_conflict' => 420,
        'room_conflict' => 430,
        'faculty_active' => 440,
        'part_time_faculty_availability' => 450,
        'class_duration' => 460,
        'hybrid_component_count' => 500,
        'hybrid_components' => 510,
        'minor_split_component_count' => 520,
        'minor_split_eligibility' => 530,
        'minor_split_pattern' => 540,
        'minor_split_duration' => 550,
        'split_group_same_time' => 555,
        'split_group_day_separation' => 560,
    ];

    private readonly DeliveryModeConstraints $deliveryModes;

    private readonly MeetingDayConstraints $meetingDays;

    private readonly OperatingHoursConstraints $operatingHours;

    private readonly RoomTypeConstraints $roomTypes;

    private readonly OverlapConflict $overlaps;

    private readonly RoomAvailabilityConstraints $roomAvailability;

    private readonly MeetingGroupConstraints $meetingGroups;

    private readonly InstructorAvailabilityConstraints $instructorAvailability;

    private readonly SectionLoadConstraints $sectionLoad;

    public function __construct()
    {
        $this->deliveryModes = new DeliveryModeConstraints;
        $this->meetingDays = new MeetingDayConstraints;
        $this->operatingHours = new OperatingHoursConstraints;
        $this->roomTypes = new RoomTypeConstraints;
        $this->overlaps = new OverlapConflict;
        $this->roomAvailability = new RoomAvailabilityConstraints;
        $this->meetingGroups = new MeetingGroupConstraints;
        $this->instructorAvailability = new InstructorAvailabilityConstraints;
        $this->sectionLoad = new SectionLoadConstraints;
    }

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

        $persisted = array_values(array_filter(
            $snapshot->persistedSchedules,
            static fn (array $schedule): bool => ! in_array((int) ($schedule['id'] ?? 0), $ignoreScheduleIds, true),
        ));
        $others = [...$persisted, ...$candidateRows];

        return $this->sortViolations([
            ...$this->deliveryModes->forRow($row, $course, $snapshot),
            ...$this->meetingDays->forRow($row, $course, $snapshot),
            ...$this->operatingHours->forRow($row, $course, $snapshot),
            ...$this->roomTypes->forRow($row, $course, $snapshot),
            ...$this->overlaps->forRow($row, $others, $snapshot),
            ...$this->roomAvailability->forRow($row, $snapshot),
            ...$this->instructorAvailability->forRow($row, $snapshot),
            ...$this->sectionLoad->forRow($row, $course, $others, $snapshot),
        ]);
    }

    /** @return list<ConstraintViolation> */
    public function evaluateMeetingGroup(MeetingGroup $group, SchedulingSnapshot $snapshot): array
    {
        $course = $snapshot->coursesById[$group->courseId] ?? null;
        if (! is_array($course)) {
            return [];
        }

        return $this->sortViolations($this->meetingGroups->forGroup($group, $course, $snapshot));
    }

    /**
     * Stable sort by RULE_PRIORITY: rules without a priority go last, and ties
     * keep the order the families reported them in.
     *
     * @param  list<ConstraintViolation>  $violations
     * @return list<ConstraintViolation>
     */
    private function sortViolations(array $violations): array
    {
        $indexed = array_map(static fn (ConstraintViolation $violation, int $index): array => [$violation, $index], $violations, array_keys($violations));
        usort($indexed, static fn (array $left, array $right): int => (self::RULE_PRIORITY[$left[0]->ruleId] ?? PHP_INT_MAX) <=> (self::RULE_PRIORITY[$right[0]->ruleId] ?? PHP_INT_MAX)
            ?: $left[1] <=> $right[1]);

        return array_map(static fn (array $item): ConstraintViolation => $item[0], $indexed);
    }
}
