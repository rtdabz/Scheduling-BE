<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * hybrid_mode, hybrid_eligibility, hybrid_component_type, hybrid_component_shape,
 * major_sunday_mode_constraint. Kernel counterpart of Rules\DeliveryModeRule.
 */
final class DeliveryModeConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forRow(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        return [
            ...$this->hybrid($row, $course, $snapshot),
            ...$this->sundayMajor($row, $course, $snapshot),
        ];
    }

    /**
     * A major meeting on Sunday must be online while the department's
     * Sunday-online setting is on. NSTP and field courses have their own limits.
     *
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    private function sundayMajor(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        if ($row->day !== 'Sunday'
            || $row->mode === 'online'
            || SchedulingConstraintPredicates::isNstpCourse($course)
            || SchedulingConstraintPredicates::isFieldCourse($course, $snapshot->fieldCourseCodes)
            || ! SchedulingConstraintPredicates::isMajorCourse($course)
            || ! (bool) ($snapshot->departmentSettings['sunday_online_only_enabled'] ?? true)) {
            return [];
        }

        return [ConstraintSupport::violation('major_sunday_mode_constraint', 'Major courses scheduled on Sunday must use online delivery mode.')];
    }

    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    private function hybrid(ScheduleRow $row, array $course, SchedulingSnapshot $snapshot): array
    {
        if ($row->mode === 'field' && $row->isHybrid) {
            return [ConstraintSupport::violation('hybrid_mode', 'Field schedules cannot be marked as hybrid.')];
        }

        if (! $row->isHybrid) {
            return [];
        }

        $hasLaboratoryComponent = (int) ($course['lab_hours'] ?? 0) > 0;
        $isHybridSplit = ! $hasLaboratoryComponent && SchedulingPolicy::hybridSplitEligible($course);
        if (! $isHybridSplit && (! SchedulingConstraintPredicates::isMajorCourse($course)
            || (int) ($course['lecture_hours'] ?? 0) <= 0
            || ! $hasLaboratoryComponent)) {
            return [ConstraintSupport::violation(
                'hybrid_eligibility',
                'Hybrid scheduling is available only for eligible course configurations.',
            )];
        }

        $expected = $hasLaboratoryComponent
            ? match ($row->meetingType) {
                'lecture' => ['mode' => 'online', 'minutes' => SchedulingPolicy::lectureComponentSlots($course) * SchedulingPolicy::SLOT_MINUTES],
                'laboratory' => ['mode' => 'on-site', 'minutes' => SchedulingPolicy::laboratoryComponentSlotsForArray($course, $snapshot->departmentSettings) * SchedulingPolicy::SLOT_MINUTES],
                default => null,
            }
            : ($row->meetingType === 'lecture'
                ? ['mode' => in_array($row->mode, ['online', 'on-site'], true) ? $row->mode : 'on-site', 'minutes' => SchedulingPolicy::HYBRID_SPLIT_MEETING_MINUTES]
                : null);
        if ($expected === null) {
            return [ConstraintSupport::violation('hybrid_component_type', 'Hybrid schedules must identify each meeting as lecture or laboratory.')];
        }

        $duration = SchedulingPolicy::timeToMinutes($row->endTime) - SchedulingPolicy::timeToMinutes($row->startTime);
        // Mirrors DeliveryModeRule: Integrated Hybrid lengths are the user's
        // to set (the weekly total is `class_duration`'s), only the delivery
        // of each session is fixed; Hybrid Split keeps its exact length.
        $wrongLength = $hasLaboratoryComponent
            ? $duration <= 0
            : $duration !== $expected['minutes'];
        if ($row->mode !== $expected['mode'] || $wrongLength) {
            return [ConstraintSupport::violation(
                'hybrid_component_shape',
                match (true) {
                    ! $hasLaboratoryComponent => 'Each Hybrid Split meeting must last the fixed Hybrid Split length.',
                    $row->meetingType === 'lecture' => 'The Integrated Hybrid lecture must be online.',
                    default => 'The Integrated Hybrid laboratory must be on-site.',
                },
            )];
        }

        return [];
    }
}
