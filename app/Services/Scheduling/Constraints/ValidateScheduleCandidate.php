<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Constraints;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleCandidate;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\ScheduleRequirement;
use App\Services\Scheduling\SchedulingPolicy;

final class ValidateScheduleCandidate
{
    public function __construct(
        private readonly SchedulingConstraintKernel $kernel,
        private readonly SchedulingConstraintEvaluationContextFactory $contexts,
    ) {}

    /** @return list<ConstraintViolation> */
    public function validate(
        ScheduleCandidate $candidate,
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
    ): array {
        $context = $this->contexts->forGeneration($configuration, $snapshot);
        $violations = [];

        foreach ($candidate->rows as $index => $row) {
            $otherRows = $context->additionalRows;
            foreach ($candidate->rows as $otherIndex => $otherRow) {
                if ($otherIndex !== $index) {
                    $otherRows[] = $otherRow;
                }
            }

            foreach ($this->kernel->evaluateRow($row, $snapshot, $otherRows, $context->ignoreScheduleIds) as $violation) {
                $violations[$this->violationKey($violation)] = $violation;
            }
        }

        foreach ($this->meetingGroups($candidate, $configuration, $snapshot) as $group) {
            foreach ($this->kernel->evaluateMeetingGroup($group, $snapshot) as $violation) {
                $violations[$this->violationKey($violation)] = $violation;
            }
        }

        return array_values($violations);
    }

    /** @return list<MeetingGroup> */
    private function meetingGroups(
        ScheduleCandidate $candidate,
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
    ): array {
        $rowsByGroup = [];
        foreach ($candidate->rows as $row) {
            if ($row->splitGroupId !== null) {
                $rowsByGroup[$row->splitGroupId][] = $row;
            } elseif ($row->isHybrid) {
                // Hybrid lecture/laboratory rows must remain one meeting
                // group even if older/manual payloads omitted split_group_id.
                // This prevents same-day components from bypassing group
                // validation at the persistence boundary.
                $rowsByGroup['hybrid:'.$row->courseId][] = $row;
            }
        }

        $groups = [];
        foreach ($rowsByGroup as $groupId => $rows) {
            $courseId = $rows[0]->courseId;
            $groups[] = new MeetingGroup(
                groupId: $groupId,
                sectionId: $configuration->sectionId,
                courseId: $courseId,
                type: $this->meetingGroupType($rows, $configuration, $courseId),
                requirements: $this->requirements($rows, $configuration, $snapshot, $courseId),
                rows: $rows,
            );
        }

        return $groups;
    }

    /** @param list<ScheduleRow> $rows */
    private function meetingGroupType(array $rows, GenerationConfiguration $configuration, int $courseId): string
    {
        if (in_array($courseId, $configuration->balancedSplitCourseIds, true)) {
            return 'minor_split';
        }

        if (array_filter($rows, static fn (ScheduleRow $row): bool => $row->isHybrid) !== []) {
            return 'hybrid';
        }

        return 'multi_day';
    }

    /**
     * @param  list<ScheduleRow>  $rows
     * @return list<ScheduleRequirement>
     */
    private function requirements(
        array $rows,
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        int $courseId,
    ): array {
        $configured = $configuration->requirementsByCourseId[$courseId] ?? [];
        if (is_array($configured) && $configured !== []) {
            return array_map(
                static fn (array $requirement): ScheduleRequirement => ScheduleRequirement::fromArray($requirement),
                array_values($configured),
            );
        }

        $course = $snapshot->coursesById[$courseId] ?? [];

        return array_map(static function (ScheduleRow $row) use ($courseId, $course): ScheduleRequirement {
            $durationSlots = intdiv(
                SchedulingPolicy::timeToMinutes($row->endTime) - SchedulingPolicy::timeToMinutes($row->startTime),
                SchedulingPolicy::SLOT_MINUTES,
            );
            $componentType = $row->meetingType ?? 'lecture';
            $roomTypes = $componentType === 'laboratory'
                ? ['laboratory']
                : [(string) ($course['room_type_required'] ?? 'lecture')];

            return new ScheduleRequirement(
                courseId: $courseId,
                componentType: $componentType,
                durationSlots: $durationSlots,
                eligibleRoomTypes: $roomTypes,
                allowedDeliveryModes: [$row->mode],
                isSplitComponent: true,
            );
        }, $rows);
    }

    private function violationKey(ConstraintViolation $violation): string
    {
        return hash('sha256', json_encode([
            $violation->ruleId,
            $violation->scope,
            $violation->context,
        ], JSON_THROW_ON_ERROR));
    }
}
