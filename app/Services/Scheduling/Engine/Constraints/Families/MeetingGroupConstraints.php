<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Rules\MeetingGroupRule;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * Rules judged on linked meetings rather than one row: hybrid_component_count,
 * hybrid_components, minor_split_component_count, minor_split_eligibility,
 * minor_split_pattern, minor_split_duration, split_group_same_time,
 * split_group_day_separation.
 * Kernel counterpart of Rules\MeetingGroupRule, whose static groupMismatches()
 * makes the decision; this side supplies the group's rows and snapshot settings.
 */
final class MeetingGroupConstraints
{
    /**
     * @param  array<string, mixed>  $course
     * @return list<ConstraintViolation>
     */
    public function forGroup(MeetingGroup $group, array $course, SchedulingSnapshot $snapshot): array
    {
        $rows = array_map(static fn (ScheduleRow $row): array => [
            'day' => $row->day,
            'start_time' => $row->startTime,
            'end_time' => $row->endTime,
            'mode' => $row->mode,
            'meeting_type' => $row->meetingType,
        ], $group->rows);

        $kind = match ($group->type) {
            'hybrid', 'minor_split' => $group->type,
            default => 'linked',
        };

        $mismatches = MeetingGroupRule::groupMismatches(
            $kind,
            $course,
            $rows,
            SchedulingPolicy::normalizePreferredPattern($group->rows[0]->preferredPattern ?? null),
            $snapshot->departmentSettings,
        );

        return array_map(
            static fn (array $mismatch): ConstraintViolation => ConstraintSupport::violation(
                $mismatch['rule'],
                $mismatch['message'],
                'meeting_group',
                ['split_group_id' => $group->groupId],
            ),
            $mismatches,
        );
    }
}
