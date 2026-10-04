<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints\Families;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Rules\MeetingGroupRule;
use App\Services\Scheduling\Support\SchedulingPolicy;

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

        $rawPattern = $group->rows[0]->preferredPattern ?? null;
        $isConsecutive = SchedulingPolicy::consecutiveDayCount($rawPattern) !== null;
        $kind = match (true) {
            $isConsecutive => 'consecutive',
            in_array($group->type, ['hybrid', 'minor_split'], true) => $group->type,
            default => 'linked',
        };

        $mismatches = MeetingGroupRule::groupMismatches(
            $kind,
            $course,
            $rows,
            $isConsecutive ? $rawPattern : SchedulingPolicy::normalizePreferredPattern($rawPattern),
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
