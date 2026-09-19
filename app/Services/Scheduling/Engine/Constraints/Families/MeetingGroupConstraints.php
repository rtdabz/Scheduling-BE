<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * Rules judged on linked meetings rather than one row: hybrid_component_count,
 * hybrid_components, minor_split_component_count, minor_split_eligibility,
 * minor_split_pattern, minor_split_duration, split_group_day_separation.
 */
final class MeetingGroupConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forGroup(MeetingGroup $group, array $course, SchedulingSnapshot $snapshot): array
    {
        $violations = [];
        $rowCount = count($group->rows);
        $groupContext = ['split_group_id' => $group->groupId];

        if ($group->type === 'hybrid') {
            if ($rowCount !== 2) {
                $violations[] = ConstraintSupport::violation(
                    'hybrid_component_count',
                    'Hybrid scheduling requires exactly one online lecture and one on-site laboratory meeting.',
                    'meeting_group',
                    $groupContext,
                );
            } elseif (
                ((int) ($course['lab_hours'] ?? 0) > 0
                    && $this->meetingTypes($group->rows) !== ['laboratory', 'lecture'])
                || ((int) ($course['lab_hours'] ?? 0) <= 0
                    && (! $this->allMeetingType($group->rows, 'lecture')
                        || $this->deliveryModes($group->rows) !== ['on-site', 'online']))
            ) {
                $violations[] = ConstraintSupport::violation(
                    'hybrid_components',
                    (int) ($course['lab_hours'] ?? 0) > 0
                        ? 'Hybrid Laboratory requires one lecture component and one laboratory component.'
                        : 'Hybrid Split requires one online and one on-site lecture component.',
                    'meeting_group',
                    $groupContext,
                );
            }
        }

        if ($group->type === 'minor_split') {
            if ($rowCount !== 2) {
                $violations[] = ConstraintSupport::violation(
                    'minor_split_component_count',
                    'Split Session scheduling requires exactly two linked meetings.',
                    'meeting_group',
                    $groupContext,
                );
            } else {
                if (! SchedulingPolicy::balancedSplitEligible($course, $snapshot->departmentSettings)) {
                    $violations[] = ConstraintSupport::violation(
                        'minor_split_eligibility',
                        'Split Session is available only for eligible minor courses or lecture-only majors.',
                        'meeting_group',
                        $groupContext,
                    );
                }

                $this->appendMinorSplitShapeViolations($violations, $group, $course);
            }
        }

        if ($rowCount > 1 && count(array_unique(array_map(static fn (ScheduleRow $row): string => $row->day, $group->rows))) !== $rowCount) {
            $violations[] = ConstraintSupport::violation(
                'split_group_day_separation',
                'Split meetings for the same course must be scheduled on different days.',
                'meeting_group',
                $groupContext,
            );
        }

        return $violations;
    }

    /** @param list<ScheduleRow> $rows */
    private function allMeetingType(array $rows, string $type): bool
    {
        return $rows !== [] && array_reduce(
            $rows,
            static fn (bool $valid, ScheduleRow $row): bool => $valid && $row->meetingType === $type,
            true,
        );
    }

    /** @param list<ScheduleRow> $rows @return list<string> */
    private function deliveryModes(array $rows): array
    {
        $modes = array_map(static fn (ScheduleRow $row): string => $row->mode, $rows);
        sort($modes);

        return $modes;
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
                $violations[] = ConstraintSupport::violation('minor_split_pattern', "Split Session {$pattern} meetings must use the configured day pair.", 'meeting_group', ['split_group_id' => $group->groupId]);
            }
        }

        $totalMinutes = array_sum(array_map(
            static fn (ScheduleRow $row): int => SchedulingPolicy::timeToMinutes($row->endTime) - SchedulingPolicy::timeToMinutes($row->startTime),
            $group->rows,
        ));
        // Mirrors MeetingGroupRule: a custom duration may shorten a Split
        // Session but never stretch it past the course's contact hours.
        $maximumMinutes = max(1, SchedulingPolicy::unitMinutes($course['units'] ?? 0));
        if ($totalMinutes <= 0 || $totalMinutes > $maximumMinutes) {
            $violations[] = ConstraintSupport::violation(
                'minor_split_duration',
                'Split Session meeting durations must not add up to more than the course contact hours.',
                'meeting_group',
                ['split_group_id' => $group->groupId],
            );
        }
    }

    /**
     * @param  list<ScheduleRow>  $rows
     * @return list<string|null>
     */
    private function meetingTypes(array $rows): array
    {
        $types = array_map(static fn (ScheduleRow $row): ?string => $row->meetingType, $rows);
        sort($types);

        return $types;
    }
}
