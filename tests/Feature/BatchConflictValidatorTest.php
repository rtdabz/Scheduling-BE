<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Schedule\BatchConflict;
use App\Services\Scheduling\Schedule\BatchConflictValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the rule module extracted for audit finding #4 — previously duplicated
 * between ScheduleController::checkIntraBatchConflicts and
 * ScheduleRecommendationController::validateBatchConflicts.
 */
class BatchConflictValidatorTest extends TestCase
{
    use RefreshDatabase;

    private BatchConflictValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(BatchConflictValidator::class);
    }

    /** @return list<string> */
    private function rules(array $rows, array $ignoreIds = []): array
    {
        return array_map(
            static fn (BatchConflict $conflict): string => $conflict->rule,
            $this->validator->validate($rows, $ignoreIds),
        );
    }

    public function test_no_conflicts_for_non_overlapping_rows(): void
    {
        [$semester, $dept, $section, $room] = $this->fixture();
        $course = $this->course('BCV101', $dept);

        $rules = $this->rules([
            $this->row($semester, $dept, $section, $course, $room, 'Monday', '08:00', '09:00'),
            $this->row($semester, $dept, $section, $course, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertSame([], $rules);
    }

    public function test_detects_section_conflict_between_overlapping_rows(): void
    {
        [$semester, $dept, $section, $room] = $this->fixture();
        $course = $this->course('BCV101', $dept);

        $rules = $this->rules([
            $this->row($semester, $dept, $section, $course, $room, 'Monday', '08:00', '09:30'),
            $this->row($semester, $dept, $section, $course, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertContains(BatchConflict::RULE_SECTION, $rules);
    }

    public function test_detects_room_conflict_for_exclusive_room_across_sections(): void
    {
        [$semester, $dept, $sectionA, $room] = $this->fixture();
        $sectionB = $this->section('BCV-1B', $dept, $semester);
        $courseA = $this->course('BCV101', $dept);
        $courseB = $this->course('BCV102', $dept);

        $rules = $this->rules([
            $this->row($semester, $dept, $sectionA, $courseA, $room, 'Monday', '08:00', '09:30'),
            $this->row($semester, $dept, $sectionB, $courseB, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertContains(BatchConflict::RULE_ROOM, $rules);
        $this->assertNotContains(BatchConflict::RULE_SECTION, $rules);
    }

    public function test_detects_faculty_conflict_across_sections(): void
    {
        [$semester, $dept, $sectionA, $room] = $this->fixture();
        $sectionB = $this->section('BCV-1B', $dept, $semester);
        $roomB = Rooms::create(['room_code' => 'BCV202', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $dept->id]);
        $courseA = $this->course('BCV101', $dept);
        $courseB = $this->course('BCV102', $dept);

        $rules = $this->rules([
            array_merge($this->row($semester, $dept, $sectionA, $courseA, $room, 'Monday', '08:00', '09:30'), ['faculty_id' => 41]),
            array_merge($this->row($semester, $dept, $sectionB, $courseB, $roomB, 'Monday', '09:00', '10:00'), ['faculty_id' => 41]),
        ]);

        $this->assertContains(BatchConflict::RULE_FACULTY, $rules);
    }

    /**
     * The batch save enforced this rule; the recommendation-accept path did not.
     * Consolidating means both do.
     */
    public function test_detects_same_course_online_for_two_sections_at_once(): void
    {
        [$semester, $dept, $sectionA] = $this->fixture();
        $sectionB = $this->section('BCV-1B', $dept, $semester);
        $course = $this->course('BCV101', $dept);

        $rules = $this->rules([
            array_merge($this->row($semester, $dept, $sectionA, $course, null, 'Monday', '08:00', '09:30'), ['mode' => 'online']),
            array_merge($this->row($semester, $dept, $sectionB, $course, null, 'Monday', '09:00', '10:00'), ['mode' => 'online']),
        ]);

        $this->assertContains(BatchConflict::RULE_SUBJECT_SECTION_TIME, $rules);
    }

    public function test_shared_field_room_is_not_a_collision_across_departments(): void
    {
        [$semester, $deptA, $sectionA] = $this->fixture();
        $deptB = Departments::create(['department_name' => 'Other Dept', 'department_code' => 'OTH']);
        $sectionB = Sections::create([
            'section_name' => 'OTH-1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $deptB->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
        $field = Rooms::create([
            'room_code' => 'FIELD', 'room_type' => 'field', 'status' => 'available',
            'department_id' => null,
        ]);
        $courseA = $this->course('PATHFIT1', $deptA, 'field');
        $courseB = $this->course('PATHFIT2', $deptB, 'field');

        $rules = $this->rules([
            array_merge($this->row($semester, $deptA, $sectionA, $courseA, $field, 'Monday', '08:00', '09:30'), ['mode' => 'field']),
            array_merge($this->row($semester, $deptB, $sectionB, $courseB, $field, 'Monday', '09:00', '10:00'), ['mode' => 'field']),
        ]);

        $this->assertNotContains(BatchConflict::RULE_ROOM, $rules);
    }

    /**
     * Field and online classes are shared without a limit, so any number of one
     * department's classes may run there at once.
     */
    public function test_concurrent_field_and_online_classes_are_never_capped(): void
    {
        [$semester, $dept] = $this->fixture();
        $field = Rooms::create([
            'room_code' => 'FIELD', 'room_type' => 'field', 'status' => 'available', 'department_id' => null,
        ]);

        $rows = [];
        foreach (['A', 'B', 'C', 'D'] as $offset => $suffix) {
            $section = $this->section("BCV-1{$suffix}", $dept, $semester);
            $rows[] = array_merge(
                $this->row($semester, $dept, $section, $this->course("BCVF{$offset}", $dept, 'field'), $field, 'Monday', '08:00', '10:00'),
                ['mode' => 'field'],
            );
            $rows[] = array_merge(
                $this->row($semester, $dept, $section, $this->course("BCVO{$offset}", $dept), null, 'Tuesday', '08:00', '10:00'),
                ['mode' => 'online'],
            );
        }

        $this->assertSame([], $this->rules($rows));
    }

    public function test_accepts_subject_id_as_an_alias_for_course_id(): void
    {
        [$semester, $dept, $section, $room] = $this->fixture();
        $course = $this->course('BCV101', $dept);

        $left = $this->row($semester, $dept, $section, $course, $room, 'Monday', '08:00', '09:30');
        unset($left['course_id']);
        $left['subject_id'] = $course->id;

        $rules = $this->rules([
            $left,
            $this->row($semester, $dept, $section, $course, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertContains(BatchConflict::RULE_SECTION, $rules);
    }

    public function test_empty_payload_yields_no_conflicts(): void
    {
        $this->assertSame([], $this->rules([]));
    }

    /** @return array{0: Semester, 1: Departments, 2: Sections, 3: Rooms} */
    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $dept = Departments::create(['department_name' => 'Batch Dept', 'department_code' => 'BCV']);
        $section = $this->section('BCV-1A', $dept, $semester);
        $room = Rooms::create([
            'room_code' => 'BCV201', 'room_type' => 'lecture', 'status' => 'available',
            'department_id' => $dept->id,
        ]);

        return [$semester, $dept, $section, $room];
    }

    private function section(string $name, Departments $dept, Semester $semester): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
    }

    private function course(string $code, Departments $dept, string $roomType = 'lecture'): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => "Course {$code}",
            'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
            'course_category' => 'major', 'room_type_required' => $roomType,
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(
        Semester $semester,
        Departments $dept,
        Sections $section,
        Course $course,
        ?Rooms $room,
        string $day,
        string $start,
        string $end,
    ): array {
        return [
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $dept->id,
            'room_id' => $room?->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
        ];
    }
}
