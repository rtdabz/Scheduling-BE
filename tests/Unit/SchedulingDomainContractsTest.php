<?php

namespace Tests\Unit;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\MeetingGroup;
use App\Services\Scheduling\Domain\ScheduleCandidate;
use App\Services\Scheduling\Domain\SchedulePlan;
use App\Services\Scheduling\Domain\SchedulePlanStatus;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Generation\ScheduleRequirement;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use Tests\TestCase;

class SchedulingDomainContractsTest extends TestCase
{
    public function test_generation_configuration_normalizes_current_solver_schema(): void
    {
        $configuration = GenerationConfiguration::fromArray([
            'sectionId' => '12',
            'courseIds' => ['4', 4, 8],
            'deliveryMode' => 'on-site',
            'preferredPatternsByCourseId' => ['4' => 'MW', '8' => null],
            'selectedSplitSessionCourseIds' => ['8'],
            'deliveryModesByCourseId' => ['4' => 'online'],
            'maxSolutions' => '3',
            'seed' => '99',
        ]);

        $this->assertSame(GenerationConfiguration::SCHEMA_VERSION, $configuration->schemaVersion);
        $this->assertSame([4, 8], $configuration->courseIds);
        $this->assertSame([4 => 'MW', 8 => null], $configuration->preferredPatternsByCourseId);
        $this->assertSame([8], $configuration->selectedSplitSessionCourseIds);
        $this->assertSame([4 => 'online'], $configuration->deliveryModesByCourseId);
        $this->assertSame(3, $configuration->maxSolutions);
        $this->assertSame(99, $configuration->seed);
        $this->assertSame($configuration->toArray(), $configuration->jsonSerialize());
    }

    public function test_schedule_row_supports_current_transport_aliases_and_room_resolution(): void
    {
        $row = ScheduleRow::fromArray([
            'semester_id' => 1,
            'section_id' => 2,
            'subject_id' => 3,
            'department_id' => 4,
            'room_id' => null,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'online',
        ]);

        $this->assertSame(3, $row->courseId);
        $this->assertTrue($row->isRoomResolved());
        $this->assertSame(3, $row->toArray()['course_id']);

        $this->expectException(Error::class);
        $row->day = 'Tuesday';
    }

    public function test_schedule_row_rejects_an_invalid_time_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScheduleRow::fromArray($this->rowPayload(start: '10:00', end: '09:00'));
    }

    public function test_meeting_group_reuses_existing_schedule_requirements(): void
    {
        $requirements = [
            new ScheduleRequirement(3, 'lecture', 4, ['online'], ['online'], isSplitComponent: true),
            new ScheduleRequirement(3, 'laboratory', 6, ['laboratory'], ['on-site'], isSplitComponent: true),
        ];
        $rows = [
            ScheduleRow::fromArray(array_merge($this->rowPayload(), [
                'split_group_id' => 'group-1', 'meeting_type' => 'lecture', 'meeting_index' => 1,
            ])),
            ScheduleRow::fromArray(array_merge($this->rowPayload(day: 'Tuesday', start: '09:00', end: '12:00'), [
                'split_group_id' => 'group-1', 'meeting_type' => 'laboratory', 'meeting_index' => 2,
            ])),
        ];

        $group = new MeetingGroup('group-1', 2, 3, 'hybrid', $requirements, $rows);

        $this->assertTrue($group->isComplete());
        $this->assertSame($group->toArray(), MeetingGroup::fromArray($group->toArray())->toArray());
    }

    public function test_hybrid_rows_without_split_group_id_still_require_different_days(): void
    {
        $configuration = GenerationConfiguration::fromArray([
            'section_id' => 3,
            'course_ids' => [4],
            'is_hybrid' => true,
        ]);
        $snapshot = new SchedulingSnapshot(
            fingerprint: str_repeat('a', 64),
            capturedAt: new \DateTimeImmutable,
            semesterId: 2,
            departmentId: 5,
            sectionsById: [3 => ['id' => 3, 'semester_id' => 2, 'department_id' => 5]],
            coursesById: [4 => ['id' => 4, 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3]],
            semester: ['id' => 2],
        );
        $rows = [
            ScheduleRow::fromArray(['semester_id' => 2, 'section_id' => 3, 'department_id' => 5, 'course_id' => 4, 'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '10:00', 'mode' => 'online', 'is_hybrid' => true, 'meeting_type' => 'lecture']),
            ScheduleRow::fromArray(['semester_id' => 2, 'section_id' => 3, 'department_id' => 5, 'course_id' => 4, 'day' => 'Monday', 'start_time' => '13:00', 'end_time' => '16:00', 'mode' => 'on-site', 'is_hybrid' => true, 'meeting_type' => 'laboratory']),
        ];

        $violations = app(\App\Services\Scheduling\Engine\Constraints\ValidateScheduleCandidate::class)
            ->validate(new ScheduleCandidate($rows), $configuration, $snapshot);

        $this->assertContains('split_group_day_separation', array_map(static fn ($violation): string => $violation->ruleId, $violations));
    }

    public function test_candidate_preserves_quality_metadata_and_detects_room_tba(): void
    {
        $candidate = ScheduleCandidate::fromArray([
            'quality_score' => 49000,
            'penalty_score' => 1000,
            'score_breakdown' => ['room_tba' => 1000],
            'section_summaries' => [['section_id' => 2]],
            'schedules' => [$this->rowPayload(roomId: null)],
        ]);

        $this->assertTrue($candidate->hasUnresolvedRooms());
        $this->assertSame(49000, $candidate->qualityScore);
        $this->assertSame([['section_id' => 2]], $candidate->metadata['section_summaries']);
    }

    public function test_constraint_violation_adapts_current_rule_payloads(): void
    {
        $violation = ConstraintViolation::fromArray([
            'rule' => 'room_conflict',
            'message' => 'Room is occupied.',
            'operation_index' => 4,
        ]);

        $this->assertSame('room_conflict', $violation->ruleId);
        $this->assertSame(4, $violation->context['operation_index']);
        $this->assertSame('hard', $violation->severity);
    }

    public function test_snapshot_is_a_versioned_serializable_boundary(): void
    {
        $snapshot = new SchedulingSnapshot(
            fingerprint: 'snapshot-sha256',
            capturedAt: new DateTimeImmutable('2026-09-01T09:30:00+08:00'),
            semesterId: 1,
            departmentId: 4,
            sectionsById: [2 => ['id' => 2]],
            coursesById: [3 => ['id' => 3]],
            forcedDaysByCourseId: [3 => 'Monday'],
            resourceLimits: ['online' => 5, 'field' => 2],
        );

        $restored = SchedulingSnapshot::fromArray($snapshot->toArray());

        $this->assertSame($snapshot->toArray(), $restored->toArray());
        $this->assertSame(SchedulingSnapshot::SCHEMA_VERSION, $restored->schemaVersion);
    }

    public function test_schedule_plan_round_trips_and_exposes_confirmation_state(): void
    {
        $plan = new SchedulePlan(
            planId: 'plan-1',
            configuration: GenerationConfiguration::fromArray([
                'section_id' => 2,
                'course_ids' => [3],
            ]),
            snapshotFingerprint: 'snapshot-sha256',
            status: SchedulePlanStatus::RoomAssignmentUnresolved,
            rows: [ScheduleRow::fromArray($this->rowPayload(roomId: null))],
            violations: [new ConstraintViolation('same_day_concentration', 'Review the configured day.', 'warning', 'configuration')],
            appliedAdjustments: [['type' => 'clear_pattern', 'course_id' => 3]],
            unresolvedResources: [['type' => 'room', 'course_id' => 3]],
            scores: ['quality_score' => 49000],
        );

        $restored = SchedulePlan::fromArray($plan->toArray());

        $this->assertTrue($plan->requiresConfirmation());
        $this->assertSame($plan->toArray(), $restored->toArray());
        $this->assertSame(SchedulePlanStatus::RoomAssignmentUnresolved, $restored->status);
    }

    public function test_committed_plan_cannot_contain_hard_violations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SchedulePlan(
            planId: 'plan-invalid',
            configuration: GenerationConfiguration::fromArray(['section_id' => 2, 'course_ids' => [3]]),
            snapshotFingerprint: 'snapshot-sha256',
            status: SchedulePlanStatus::Committed,
            rows: [ScheduleRow::fromArray($this->rowPayload())],
            violations: [new ConstraintViolation('room_conflict', 'Room is occupied.')],
        );
    }

    /** @return array<string, mixed> */
    private function rowPayload(
        string $day = 'Monday',
        string $start = '08:00',
        string $end = '09:00',
        ?int $roomId = 5,
    ): array {
        return [
            'semester_id' => 1,
            'section_id' => 2,
            'course_id' => 3,
            'department_id' => 4,
            'room_id' => $roomId,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
            'status' => 'draft',
        ];
    }
}
