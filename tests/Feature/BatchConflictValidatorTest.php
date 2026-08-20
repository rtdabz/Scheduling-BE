<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\BatchConflict;
use App\Services\Scheduling\BatchConflictValidator;
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
        [$term, $dept, $section, $room] = $this->fixture();
        $course = $this->course('BCV101', $dept);

        $rules = $this->rules([
            $this->row($term, $dept, $section, $course, $room, 'Monday', '08:00', '09:00'),
            $this->row($term, $dept, $section, $course, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertSame([], $rules);
    }

    public function test_detects_section_conflict_between_overlapping_rows(): void
    {
        [$term, $dept, $section, $room] = $this->fixture();
        $course = $this->course('BCV101', $dept);

        $rules = $this->rules([
            $this->row($term, $dept, $section, $course, $room, 'Monday', '08:00', '09:30'),
            $this->row($term, $dept, $section, $course, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertContains(BatchConflict::RULE_SECTION, $rules);
    }

    public function test_detects_room_conflict_for_exclusive_room_across_sections(): void
    {
        [$term, $dept, $sectionA, $room] = $this->fixture();
        $sectionB = $this->section('BCV-1B', $dept, $term);
        $courseA = $this->course('BCV101', $dept);
        $courseB = $this->course('BCV102', $dept);

        $rules = $this->rules([
            $this->row($term, $dept, $sectionA, $courseA, $room, 'Monday', '08:00', '09:30'),
            $this->row($term, $dept, $sectionB, $courseB, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertContains(BatchConflict::RULE_ROOM, $rules);
        $this->assertNotContains(BatchConflict::RULE_SECTION, $rules);
    }

    public function test_detects_faculty_conflict_across_sections(): void
    {
        [$term, $dept, $sectionA, $room] = $this->fixture();
        $sectionB = $this->section('BCV-1B', $dept, $term);
        $roomB = Rooms::create(['room_code' => 'BCV202', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $dept->id]);
        $courseA = $this->course('BCV101', $dept);
        $courseB = $this->course('BCV102', $dept);

        $rules = $this->rules([
            array_merge($this->row($term, $dept, $sectionA, $courseA, $room, 'Monday', '08:00', '09:30'), ['faculty_id' => 41]),
            array_merge($this->row($term, $dept, $sectionB, $courseB, $roomB, 'Monday', '09:00', '10:00'), ['faculty_id' => 41]),
        ]);

        $this->assertContains(BatchConflict::RULE_FACULTY, $rules);
    }

    /**
     * The batch save enforced this rule; the recommendation-accept path did not.
     * Consolidating means both do.
     */
    public function test_detects_same_course_online_for_two_sections_at_once(): void
    {
        [$term, $dept, $sectionA] = $this->fixture();
        $sectionB = $this->section('BCV-1B', $dept, $term);
        $course = $this->course('BCV101', $dept);

        $rules = $this->rules([
            array_merge($this->row($term, $dept, $sectionA, $course, null, 'Monday', '08:00', '09:30'), ['mode' => 'online']),
            array_merge($this->row($term, $dept, $sectionB, $course, null, 'Monday', '09:00', '10:00'), ['mode' => 'online']),
        ]);

        $this->assertContains(BatchConflict::RULE_SUBJECT_SECTION_TIME, $rules);
    }

    public function test_shared_field_room_is_not_a_collision_across_departments(): void
    {
        [$term, $deptA, $sectionA] = $this->fixture();
        $deptB = Departments::create(['department_name' => 'Other Dept', 'department_code' => 'OTH']);
        $sectionB = Sections::create([
            'section_name' => 'OTH-1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $deptB->id, 'term_id' => $term->id, 'status' => 'active',
        ]);
        $field = Rooms::create([
            'room_code' => 'FIELD', 'room_type' => 'field', 'status' => 'available',
            'department_id' => null, 'max_concurrent_classes' => 1,
        ]);
        $courseA = $this->course('PATHFIT1', $deptA, 'field');
        $courseB = $this->course('PATHFIT2', $deptB, 'field');

        $rules = $this->rules([
            array_merge($this->row($term, $deptA, $sectionA, $courseA, $field, 'Monday', '08:00', '09:30'), ['mode' => 'field']),
            array_merge($this->row($term, $deptB, $sectionB, $courseB, $field, 'Monday', '09:00', '10:00'), ['mode' => 'field']),
        ]);

        $this->assertNotContains(BatchConflict::RULE_ROOM, $rules);
    }

    public function test_reports_room_capacity_conflict_once_the_department_limit_is_exceeded(): void
    {
        [$term, $dept, $sectionA] = $this->fixture();
        $dept->update(['field_slot_limit' => 2]);
        $field = Rooms::create([
            'room_code' => 'FIELD', 'room_type' => 'field', 'status' => 'available',
            'department_id' => null, 'max_concurrent_classes' => 5,
        ]);

        $rows = [];
        foreach (['A', 'B', 'C'] as $offset => $suffix) {
            $section = $this->section("BCV-1{$suffix}", $dept, $term);
            $course = $this->course("BCVF{$offset}", $dept, 'field');
            $rows[] = array_merge(
                $this->row($term, $dept, $section, $course, $field, 'Monday', '08:00', '10:00'),
                ['mode' => 'field'],
            );
        }

        $this->assertContains(BatchConflict::RULE_ROOM_CAPACITY, $this->rules($rows));

        // Two concurrent classes sit exactly on the limit.
        $this->assertNotContains(BatchConflict::RULE_ROOM_CAPACITY, $this->rules(array_slice($rows, 0, 2)));
    }

    /**
     * The sweep reports the *candidate* row that pushes concurrency past the
     * limit, so the persisted rows it collides with must start earlier. A
     * candidate that starts at the same minute as everything else is processed
     * first and sees an empty window — behaviour preserved from the original
     * duplicated implementations, see the sprint notes in the audit report.
     *
     * Rooms whose limit is 1 never enter this sweep at all: exclusive-room
     * collisions with persisted rows are RuleEngine::checkRoomConflict's job.
     */
    public function test_counts_persisted_rows_against_shared_room_capacity(): void
    {
        [$term, $dept, $sectionA] = $this->fixture();
        $dept->update(['field_slot_limit' => 2]);
        $field = Rooms::create([
            'room_code' => 'FIELD', 'room_type' => 'field', 'status' => 'available',
            'department_id' => null, 'max_concurrent_classes' => 5,
        ]);
        $courseA = $this->course('PATHFIT1', $dept, 'field');

        $persistedIds = [];
        foreach (['B', 'C'] as $offset => $suffix) {
            $attributes = array_merge(
                $this->row(
                    $term,
                    $dept,
                    $this->section("BCV-1{$suffix}", $dept, $term),
                    $this->course("PATHFITP{$offset}", $dept, 'field'),
                    $field,
                    'Monday',
                    '08:00',
                    '10:00',
                ),
                ['mode' => 'field', 'status' => 'draft'],
            );
            $persistedIds[] = (int) Schedule::create($attributes)->id;
        }

        $candidate = [array_merge(
            $this->row($term, $dept, $sectionA, $courseA, $field, 'Monday', '09:00', '10:00'),
            ['mode' => 'field'],
        )];

        $this->assertContains(BatchConflict::RULE_ROOM_CAPACITY, $this->rules($candidate));

        // Ignoring the persisted rows — the batch is replacing them — clears the
        // conflict. The recommendation-accept path used to omit these ids and so
        // counted rows it was about to delete.
        $this->assertNotContains(
            BatchConflict::RULE_ROOM_CAPACITY,
            $this->rules($candidate, $persistedIds),
        );
    }

    public function test_ignored_schedule_ids_are_excluded_from_online_capacity_counting(): void
    {
        [$term, $dept, $sectionA] = $this->fixture();
        $dept->update(['online_slot_limit' => 1]);
        $courseA = $this->course('BCV101', $dept);
        $courseB = $this->course('BCV102', $dept);
        $sectionB = $this->section('BCV-1B', $dept, $term);

        $persisted = Schedule::create(array_merge(
            $this->row($term, $dept, $sectionB, $courseB, null, 'Monday', '08:00', '10:00'),
            ['mode' => 'online', 'status' => 'draft'],
        ));

        $candidate = [array_merge(
            $this->row($term, $dept, $sectionA, $courseA, null, 'Monday', '09:00', '10:00'),
            ['mode' => 'online'],
        )];

        $this->assertContains(BatchConflict::RULE_ONLINE_CAPACITY, $this->rules($candidate));
        $this->assertNotContains(
            BatchConflict::RULE_ONLINE_CAPACITY,
            $this->rules($candidate, [(int) $persisted->id]),
        );
    }

    public function test_accepts_subject_id_as_an_alias_for_course_id(): void
    {
        [$term, $dept, $section, $room] = $this->fixture();
        $course = $this->course('BCV101', $dept);

        $left = $this->row($term, $dept, $section, $course, $room, 'Monday', '08:00', '09:30');
        unset($left['course_id']);
        $left['subject_id'] = $course->id;

        $rules = $this->rules([
            $left,
            $this->row($term, $dept, $section, $course, $room, 'Monday', '09:00', '10:00'),
        ]);

        $this->assertContains(BatchConflict::RULE_SECTION, $rules);
    }

    public function test_empty_payload_yields_no_conflicts(): void
    {
        $this->assertSame([], $this->rules([]));
    }

    /** @return array{0: Terms, 1: Departments, 2: Sections, 3: Rooms} */
    private function fixture(): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $dept = Departments::create(['department_name' => 'Batch Dept', 'department_code' => 'BCV']);
        $section = $this->section('BCV-1A', $dept, $term);
        $room = Rooms::create([
            'room_code' => 'BCV201', 'room_type' => 'lecture', 'status' => 'available',
            'department_id' => $dept->id, 'max_concurrent_classes' => 1,
        ]);

        return [$term, $dept, $section, $room];
    }

    private function section(string $name, Departments $dept, Terms $term): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'term_id' => $term->id, 'status' => 'active',
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
        Terms $term,
        Departments $dept,
        Sections $section,
        Course $course,
        ?Rooms $room,
        string $day,
        string $start,
        string $end,
    ): array {
        return [
            'term_id' => $term->id,
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
