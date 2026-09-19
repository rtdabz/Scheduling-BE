<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Rooms;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Engine\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Support\SchedulingPolicy;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Rules RuleEngine enforces on manual saves that the kernel used to skip, so a
 * generated plan could commit what a later manual edit would refuse.
 */
class KernelRuleEngineParityTest extends TestCase
{
    private const COURSE = 10;

    private const PART_TIMER = 7;

    private const INACTIVE = 8;

    private const OPEN_ROOM = 30;

    private const CLOSED_ROOM = 31;

    private const FOREIGN_ROOM = 32;

    private const GRANTED_ROOM = 33;

    private const UNKNOWN_ROOM = 34;

    public function test_class_duration_counts_the_plans_own_meetings_together(): void
    {
        // 3 units = 180 minutes a week; two 2-hour meetings are 240.
        $first = $this->row(self::COURSE, 'Monday', '08:00:00', '10:00:00');
        $second = $this->row(self::COURSE, 'Wednesday', '08:00:00', '10:00:00');

        $this->assertContains('class_duration', $this->rules($this->kernel()->evaluateRow($first, $this->snapshot(), [$second])));
        $this->assertNotContains('class_duration', $this->rules($this->kernel()->evaluateRow($first, $this->snapshot())));
    }

    public function test_class_duration_does_not_block_data_that_was_already_over(): void
    {
        $persisted = [
            $this->persisted(1, self::COURSE, 'Monday', '08:00:00', '12:00:00'),
        ];
        // Re-placing the same 4 hours adds nothing, like RuleEngine's leniency.
        $row = $this->row(self::COURSE, 'Tuesday', '08:00:00', '12:00:00');

        $this->assertNotContains('class_duration', $this->rules(
            $this->kernel()->evaluateRow($row, $this->snapshot($persisted), ignoreScheduleIds: [1]),
        ));
    }

    public function test_class_duration_counts_rejected_and_revision_meetings(): void
    {
        foreach (['rejected', 'revision'] as $status) {
            $persisted = ['status' => $status] + $this->persisted(1, self::COURSE, 'Monday', '08:00:00', '10:00:00');
            $row = $this->row(self::COURSE, 'Friday', '08:00:00', '10:00:00');

            $this->assertContains('class_duration', $this->rules($this->kernel()->evaluateRow($row, $this->snapshot([$persisted]))), $status);
        }
    }

    public function test_a_section_has_no_cap_on_online_courses(): void
    {
        $others = array_map(
            fn (int $courseId): array => $this->persisted($courseId, $courseId, 'Friday', '13:00:00', '14:00:00', 'online'),
            [21, 22, 23, 24, 25, 26],
        );

        $seventh = $this->row(self::COURSE, 'Monday', '08:00:00', '09:00:00', mode: 'online');

        $this->assertSame([], $this->rules($this->kernel()->evaluateRow($seventh, $this->snapshot($others))));
    }

    public function test_part_time_instructor_must_be_covered_by_availability(): void
    {
        $inside = $this->row(self::COURSE, 'Monday', '09:00:00', '10:00:00', facultyId: self::PART_TIMER);
        $outside = $this->row(self::COURSE, 'Tuesday', '09:00:00', '10:00:00', facultyId: self::PART_TIMER);

        $this->assertNotContains('part_time_faculty_availability', $this->rules($this->kernel()->evaluateRow($inside, $this->snapshot())));
        $this->assertContains('part_time_faculty_availability', $this->rules($this->kernel()->evaluateRow($outside, $this->snapshot())));
    }

    public function test_inactive_instructor_is_refused(): void
    {
        $row = $this->row(self::COURSE, 'Monday', '08:00:00', '09:00:00', facultyId: self::INACTIVE);

        $this->assertContains('faculty_active', $this->rules($this->kernel()->evaluateRow($row, $this->snapshot())));
    }

    public function test_lab_for_lecture_answers_the_same_for_model_and_snapshot_forms(): void
    {
        $lab = ['room_type' => 'laboratory', 'allow_lecture_usage' => true];
        $major = ['course_category' => 'major', 'lecture_hours' => 3, 'lab_hours' => 0, 'room_type_required' => 'lecture'];
        $uncategorised = ['course_category' => null] + $major;

        foreach ([$major, $uncategorised] as $course) {
            $this->assertSame(
                SchedulingPolicy::laboratoryServesLecture($course, $lab),
                SchedulingPolicy::laboratoryServesLecture((new Course)->forceFill($course), (new Rooms)->forceFill($lab)),
            );
        }

        $this->assertTrue(SchedulingPolicy::laboratoryServesLecture($major, $lab));
        // Used to count as major on the model path only.
        $this->assertFalse(SchedulingPolicy::laboratoryServesLecture((new Course)->forceFill($uncategorised), (new Rooms)->forceFill($lab)));
    }

    public function test_a_room_marked_unavailable_is_refused(): void
    {
        $closed = $this->row(self::COURSE, 'Monday', '08:00:00', '09:00:00', roomId: self::CLOSED_ROOM);
        $open = $this->row(self::COURSE, 'Monday', '08:00:00', '09:00:00', roomId: self::OPEN_ROOM);

        $this->assertContains('room_availability', $this->rules($this->kernel()->evaluateRow($closed, $this->snapshot())));
        $this->assertNotContains('room_availability', $this->rules($this->kernel()->evaluateRow($open, $this->snapshot())));
    }

    public function test_an_online_row_still_holding_a_room_occupies_it(): void
    {
        $legacyOnline = ['room_id' => self::OPEN_ROOM] + $this->persisted(40, 21, 'Monday', '08:00:00', '09:00:00', 'online');
        $row = $this->row(self::COURSE, 'Monday', '08:30:00', '09:30:00', roomId: self::OPEN_ROOM);

        $this->assertContains('room_conflict', $this->rules($this->kernel()->evaluateRow($row, $this->snapshot([$legacyOnline]))));
    }

    public function test_times_are_judged_against_the_hours_pinned_in_the_snapshot(): void
    {
        // Snapshot hours are 07:00-19:00.
        $late = $this->row(self::COURSE, 'Monday', '18:00:00', '20:00:00');
        $offGrid = $this->row(self::COURSE, 'Monday', '08:15:00', '09:15:00');
        $inside = $this->row(self::COURSE, 'Monday', '17:00:00', '19:00:00');

        $this->assertContains('operating_hours', $this->rules($this->kernel()->evaluateRow($late, $this->snapshot())));
        $this->assertContains('slot_grid', $this->rules($this->kernel()->evaluateRow($offGrid, $this->snapshot())));
        $this->assertNotContains('operating_hours', $this->rules($this->kernel()->evaluateRow($offGrid, $this->snapshot())));
        $inside = $this->rules($this->kernel()->evaluateRow($inside, $this->snapshot()));
        $this->assertSame([], array_values(array_intersect($inside, ['slot_grid', 'operating_hours'])));
    }

    public function test_day_must_match_the_preferred_pattern(): void
    {
        $offPattern = new ScheduleRow(
            semesterId: 1, sectionId: 1, courseId: self::COURSE, departmentId: 1,
            day: 'Friday', startTime: '08:00:00', endTime: '09:00:00', mode: 'on-site',
            preferredPattern: 'MW',
        );
        $onPattern = new ScheduleRow(
            semesterId: 1, sectionId: 1, courseId: self::COURSE, departmentId: 1,
            day: 'Wednesday', startTime: '08:00:00', endTime: '09:00:00', mode: 'on-site',
            preferredPattern: 'MW',
        );

        $this->assertContains('preferred_pattern', $this->rules($this->kernel()->evaluateRow($offPattern, $this->snapshot())));
        $this->assertNotContains('preferred_pattern', $this->rules($this->kernel()->evaluateRow($onPattern, $this->snapshot())));
    }

    public function test_classification_answers_the_same_for_model_and_snapshot_forms(): void
    {
        $courses = [
            ['course_code' => 'NSTP 1', 'course_name' => 'CWTS', 'course_category' => 'minor', 'room_type_required' => 'lecture', 'lecture_hours' => 3, 'lab_hours' => 0],
            ['course_code' => 'PE 1', 'course_name' => 'PATHFIT', 'course_category' => 'minor', 'room_type_required' => 'field', 'lecture_hours' => 2, 'lab_hours' => 0],
            ['course_code' => 'CS 102', 'course_name' => 'Programming', 'course_category' => 'major', 'room_type_required' => 'laboratory', 'lecture_hours' => 2, 'lab_hours' => 1],
            ['course_code' => 'CS 101', 'course_name' => 'Intro', 'course_category' => 'major', 'room_type_required' => 'lecture', 'lecture_hours' => 3, 'lab_hours' => 0],
        ];
        $codes = ['PE 1'];

        foreach ($courses as $course) {
            $model = (new Course)->forceFill($course);
            $label = $course['course_code'];

            $this->assertSame(SchedulingPolicy::isNstpCourse($model), SchedulingPolicy::isNstpCourse($course), $label);
            $this->assertSame(SchedulingPolicy::isMajorCourse($model), SchedulingPolicy::isMajorCourse($course), $label);
            $this->assertSame(SchedulingPolicy::isLaboratoryCourse($model), SchedulingPolicy::isLaboratoryCourse($course), $label);
            $this->assertSame(SchedulingPolicy::isLectureOnlyMajor($model), SchedulingPolicy::isLectureOnlyMajor($course), $label);
            foreach ([null, 'lecture', 'laboratory'] as $meetingType) {
                $this->assertSame(
                    SchedulingPolicy::effectiveRoomType($model, null, $meetingType, $codes),
                    SchedulingPolicy::effectiveRoomType($course, null, $meetingType, $codes),
                    $label,
                );
            }
        }

        $this->assertTrue(SchedulingPolicy::isFieldCourse($courses[1], fieldCourseCodes: []));
        $this->assertTrue(SchedulingPolicy::isFieldCourse(['room_type_required' => 'lecture'] + $courses[1], fieldCourseCodes: [' pe  1 ']));
        $this->assertFalse(SchedulingPolicy::isFieldCourse($courses[0], fieldCourseCodes: $codes));
    }

    public function test_another_departments_room_needs_a_grant_covering_the_meeting(): void
    {
        $access = fn (int $roomId, string $day, string $start, string $end): bool => in_array(
            'room_department_alignment',
            $this->rules($this->kernel()->evaluateRow($this->row(self::COURSE, $day, $start, $end, roomId: $roomId), $this->snapshot())),
            true,
        );

        $this->assertTrue($access(self::FOREIGN_ROOM, 'Monday', '08:00:00', '09:00:00'), 'no grant');
        $this->assertFalse($access(self::GRANTED_ROOM, 'Monday', '09:00:00', '11:00:00'), 'inside the grant');
        $this->assertTrue($access(self::GRANTED_ROOM, 'Monday', '11:00:00', '13:00:00'), 'past the grant');
        $this->assertTrue($access(self::GRANTED_ROOM, 'Tuesday', '09:00:00', '10:00:00'), 'wrong day');
        $this->assertTrue($access(self::UNKNOWN_ROOM, 'Monday', '08:00:00', '09:00:00'), 'not in the snapshot');
        $this->assertFalse($access(self::OPEN_ROOM, 'Monday', '08:00:00', '09:00:00'), 'own room');
    }

    private function kernel(): SchedulingConstraintKernel
    {
        return new SchedulingConstraintKernel;
    }

    /** @param list<ConstraintViolation> $violations */
    private function rules(array $violations): array
    {
        return array_map(static fn (ConstraintViolation $violation): string => $violation->ruleId, $violations);
    }

    private function row(int $courseId, string $day, string $start, string $end, string $mode = 'on-site', ?int $facultyId = null, ?int $roomId = null): ScheduleRow
    {
        return new ScheduleRow(
            semesterId: 1,
            sectionId: 1,
            courseId: $courseId,
            departmentId: 1,
            day: $day,
            startTime: $start,
            endTime: $end,
            mode: $mode,
            facultyId: $facultyId,
            roomId: $roomId,
        );
    }

    /** @return array<string, mixed> */
    private function persisted(int $id, int $courseId, string $day, string $start, string $end, string $mode = 'on-site'): array
    {
        return [
            'id' => $id,
            'semester_id' => 1,
            'section_id' => 1,
            'course_id' => $courseId,
            'department_id' => 1,
            'room_id' => null,
            'faculty_id' => null,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => $mode,
            'status' => 'draft',
        ];
    }

    private function snapshot(array $persisted = []): SchedulingSnapshot
    {
        return new SchedulingSnapshot(
            fingerprint: 'parity-test',
            capturedAt: new DateTimeImmutable,
            semesterId: 1,
            departmentId: 1,
            coursesById: [
                self::COURSE => ['id' => self::COURSE, 'course_code' => 'CS 101', 'course_category' => 'major', 'room_type_required' => 'lecture', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3],
                21 => ['id' => 21, 'course_code' => 'CS 121', 'course_category' => 'major', 'room_type_required' => 'lecture', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3],
            ],
            roomsById: [
                self::OPEN_ROOM => ['id' => self::OPEN_ROOM, 'room_code' => 'R201', 'room_type' => 'lecture', 'status' => 'available'],
                self::CLOSED_ROOM => ['id' => self::CLOSED_ROOM, 'room_code' => 'R202', 'room_type' => 'lecture', 'status' => 'unavailable'],
                self::FOREIGN_ROOM => ['id' => self::FOREIGN_ROOM, 'room_code' => 'B101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2],
                self::GRANTED_ROOM => [
                    'id' => self::GRANTED_ROOM, 'room_code' => 'B102', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => 2,
                    'grant_windows' => [['day' => 'Monday', 'start_time' => '08:00', 'end_time' => '12:00']],
                ],
            ],
            persistedSchedules: $persisted,
            operatingHours: ['opening_time' => '07:00:00', 'closing_time' => '19:00:00', 'field_end_time' => '17:00:00'],
            facultiesById: [
                self::PART_TIMER => [
                    'id' => self::PART_TIMER,
                    'status' => 'active',
                    'employment_type' => 'part-time',
                    'availabilities' => [
                        ['day_index' => 0, 'start_time' => '08:00', 'end_time' => '09:30'],
                        ['day_index' => 0, 'start_time' => '09:30', 'end_time' => '12:00'],
                    ],
                ],
                self::INACTIVE => ['id' => self::INACTIVE, 'status' => 'inactive', 'employment_type' => 'full-time', 'availabilities' => []],
            ],
        );
    }
}
