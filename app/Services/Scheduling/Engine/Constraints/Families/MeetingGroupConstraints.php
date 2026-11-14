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

        return [
            ...array_map(
                static fn (array $mismatch): ConstraintViolation => ConstraintSupport::violation(
                    $mismatch['rule'],
                    $mismatch['message'],
                    'meeting_group',
                    ['split_group_id' => $group->groupId],
                ),
                $mismatches,
            ),
            ...$this->componentDayViolations($group, $course),
        ];
    }

    /**
     * A pinned Integrated component must appear exactly once, on its day.
     * Matched by meeting type, never by row order or duration.
     *
     * @return list<ConstraintViolation>
     */
    public function componentDayViolations(MeetingGroup $group, array $course): array
    {
        $violations = [];
        $code = (string) ($course['course_code'] ?? "Course {$group->courseId}");
        foreach ($group->requirements as $requirement) {
            if ($requirement->requiredDay === null) {
                continue;
            }
            $matching = array_values(array_filter(
                $group->rows,
                static fn (ScheduleRow $row): bool => $row->meetingType === $requirement->componentType,
            ));
            if (count($matching) === 1 && $matching[0]->day === $requirement->requiredDay) {
                continue;
            }
            $violations[] = ConstraintSupport::violation(
                'integrated_component_required_day',
                sprintf(
                    '%s: the %s must meet once, on %s (its %s Required Day).',
                    $code,
                    $requirement->componentType,
                    $requirement->requiredDay,
                    ucfirst($requirement->componentType),
                ),
                'meeting_group',
                [
                    'split_group_id' => $group->groupId,
                    'component_type' => $requirement->componentType,
                    'required_day' => $requirement->requiredDay,
                ],
            );
        }

        return $violations;
    }
}
