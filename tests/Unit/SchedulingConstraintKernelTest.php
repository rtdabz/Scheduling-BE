<?php

namespace Tests\Unit;

use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\Families\MeetingDayConstraints;
use App\Services\Scheduling\Engine\Constraints\Families\RoomTypeConstraints;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Support\SchedulingPolicy;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Pins the kernel after its rules moved into Families/: each family still
 * answers on its own, and the kernel still merges them in RULE_PRIORITY order.
 */
class SchedulingConstraintKernelTest extends TestCase
{
    private const MINOR = 10;

    private const NSTP = 11;

    private const LECTURE_ROOM = 20;

    public function test_the_day_family_limits_no_course_by_its_category(): void
    {
        $days = new MeetingDayConstraints;
        $snapshot = $this->snapshot();

        // Minors were Monday-Saturday and field courses Monday-Friday. Every
        // course may now use every day; only a pattern or a Required Day
        // narrows it.
        foreach ([self::MINOR, self::NSTP] as $courseId) {
            foreach (SchedulingPolicy::PERSISTABLE_DAYS as $day) {
                $this->assertSame(
                    [],
                    $this->rules($days->forRow($this->row($courseId, $day), $snapshot->coursesById[$courseId], $snapshot)),
                    "{$courseId} should be allowed on {$day}",
                );
            }
        }
    }

    public function test_room_mode_family_reports_a_field_row_in_a_lecture_room_once(): void
    {
        $snapshot = $this->snapshot();
        $row = $this->row(self::MINOR, 'Monday', mode: 'field', roomId: self::LECTURE_ROOM);

        $this->assertSame(
            ['room_type_match'],
            $this->rules((new RoomTypeConstraints)->forRow($row, $snapshot->coursesById[self::MINOR], $snapshot)),
        );
    }

    public function test_kernel_reports_a_persisted_section_clash_with_its_schedule_id(): void
    {
        $snapshot = $this->snapshot(persisted: [$this->persisted(id: 99, sectionId: 1, roomId: null)]);

        $violations = (new SchedulingConstraintKernel)->evaluateRow($this->row(self::MINOR, 'Monday'), $snapshot);

        $this->assertSame(['section_conflict'], $this->rules($violations));
        $this->assertSame(99, $violations[0]->context['conflicting_schedule_id']);
    }

    public function test_kernel_ignores_schedules_it_is_told_to_ignore(): void
    {
        $snapshot = $this->snapshot(persisted: [$this->persisted(id: 99, sectionId: 1, roomId: self::LECTURE_ROOM)]);

        $violations = (new SchedulingConstraintKernel)->evaluateRow($this->row(self::MINOR, 'Monday'), $snapshot, ignoreScheduleIds: [99]);

        $this->assertSame([], $violations);
    }

    public function test_kernel_orders_findings_from_different_families_by_priority(): void
    {
        // Another section in the same room at the same time, and a pattern this
        // day is not part of: the pattern rule (205) outranks the room clash
        // (430).
        $snapshot = $this->snapshot(persisted: [$this->persisted(id: 7, sectionId: 2, roomId: self::LECTURE_ROOM, day: 'Sunday')]);

        $violations = (new SchedulingConstraintKernel)->evaluateRow(
            $this->row(self::MINOR, 'Sunday', preferredPattern: 'MW'),
            $snapshot,
        );

        $this->assertSame(['preferred_pattern', 'room_conflict'], $this->rules($violations));
    }

    /** @param list<ConstraintViolation> $violations */
    private function rules(array $violations): array
    {
        return array_map(static fn (ConstraintViolation $violation): string => $violation->ruleId, $violations);
    }

    private function row(int $courseId, string $day, string $mode = 'on-site', ?int $roomId = self::LECTURE_ROOM, ?string $preferredPattern = null): ScheduleRow
    {
        return new ScheduleRow(
            semesterId: 1,
            sectionId: 1,
            courseId: $courseId,
            departmentId: 1,
            day: $day,
            startTime: '08:00:00',
            endTime: '09:00:00',
            mode: $mode,
            roomId: $roomId,
            preferredPattern: $preferredPattern,
        );
    }

    /** @return array<string, mixed> */
    private function persisted(int $id, int $sectionId, ?int $roomId, string $day = 'Monday'): array
    {
        return [
            'id' => $id,
            'semester_id' => 1,
            'section_id' => $sectionId,
            'course_id' => 30,
            'department_id' => 1,
            'room_id' => $roomId,
            'faculty_id' => null,
            'day' => $day,
            'start_time' => '08:30:00',
            'end_time' => '09:30:00',
            'mode' => 'on-site',
        ];
    }

    private function snapshot(array $persisted = []): SchedulingSnapshot
    {
        return new SchedulingSnapshot(
            fingerprint: 'kernel-test',
            capturedAt: new DateTimeImmutable,
            semesterId: 1,
            departmentId: 1,
            coursesById: [
                self::MINOR => ['id' => self::MINOR, 'course_code' => 'GEC 1', 'course_name' => 'Purposive Communication', 'course_category' => 'minor', 'room_type_required' => 'lecture', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3],
                self::NSTP => ['id' => self::NSTP, 'course_code' => 'NSTP 1', 'course_name' => 'CWTS', 'course_category' => 'minor', 'room_type_required' => 'lecture', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3],
            ],
            roomsById: [
                self::LECTURE_ROOM => ['id' => self::LECTURE_ROOM, 'room_type' => 'lecture'],
            ],
            persistedSchedules: $persisted,
        );
    }
}
