<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseTeachingAssignment;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\RuleEngine;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleEngineSplitValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_subject_for_different_sections_cannot_overlap_online(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'online_slot_limit' => 3,
        ]);
        $firstSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $secondSection = Sections::create([
            'section_name' => 'BSIT 2C',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'IT 108',
            'course_name' => 'Web Systems and Technologies',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        Schedule::create([
            'term_id' => $term->id,
            'section_id' => $firstSection->id,
            'course_id' => $course->id,
            'room_id' => null,
            'department_id' => $department->id,
            'day' => 'Wednesday',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'mode' => 'online',
            'status' => 'draft',
        ]);

        $attempt = [
            'term_id' => $term->id,
            'section_id' => $secondSection->id,
            'course_id' => $course->id,
            'room_id' => null,
            'department_id' => $department->id,
            'day' => 'Wednesday',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'mode' => 'online',
        ];

        $overlappingRules = collect(app(RuleEngine::class)->validate($attempt))->pluck('rule')->all();
        $nextSlotRules = collect(app(RuleEngine::class)->validate(array_merge($attempt, [
            'start_time' => '15:00',
            'end_time' => '17:00',
        ])))->pluck('rule')->all();

        $this->assertContains('subject_section_time_conflict', $overlappingRules);
        $this->assertNotContains('room_conflict', $overlappingRules);
        $this->assertNotContains('subject_section_time_conflict', $nextSlotRules);
    }

    public function test_minor_course_is_valid_on_saturday_but_not_sunday(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Department 1',
            'department_code' => 'DEPT1',
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'GEC 1',
            'course_name' => 'Understanding the Self',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => null,
            'status' => 'active',
        ]);
        $room = Rooms::create([
            'room_code' => 'IT 101',
            'room_name' => 'IT Room 101',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $attempt = [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'start_time' => '08:00',
            'end_time' => '11:00',
            'mode' => 'on-site',
        ];

        $saturdayRules = collect(app(RuleEngine::class)->validate(array_merge($attempt, [
            'day' => 'Saturday',
        ])))->pluck('rule')->all();
        $sundayRules = collect(app(RuleEngine::class)->validate(array_merge($attempt, [
            'day' => 'Sunday',
        ])))->pluck('rule')->all();

        $this->assertNotContains('minor_day_constraint', $saturdayRules);
        $this->assertContains('minor_day_constraint', $sundayRules);
    }

    public function test_split_schedule_validates_relational_integrity_and_conflicts()
    {
        // 1. Setup Active Term
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        // 2. Setup Departments
        $dept1 = Departments::create([
            'department_name' => 'Department 1',
            'department_code' => 'DEPT1',
        ]);
        $dept2 = Departments::create([
            'department_name' => 'Department 2',
            'department_code' => 'DEPT2',
        ]);

        // 3. Setup Course owned by Dept 2
        $course = Course::create([
            'course_code' => 'IT102',
            'course_name' => 'Computer Programming 1',
            'lecture_hours' => 2,
            'lab_hours' => 3,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept2->id,
            'status' => 'active',
        ]);

        // 4. Setup Section belonging to Dept 1 (triggers mismatch since Course belongs to Dept 2)
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept1->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        // 5. Setup Room
        $room = Rooms::create([
            'room_code' => 'IT105',
            'room_name' => 'IT Room 105',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $dept1->id,
        ]);

        $ruleEngine = app(RuleEngine::class);

        // Scenario A: Non-split schedule validation.
        // It should flag department alignment mismatch (IT102 belongs to DEPT2, Section belongs to DEPT1).
        $nonSplitAttempt = [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'day' => 'Monday',
            'start_time' => '10:00',
            'end_time' => '13:00',
            'mode' => 'on-site',
        ];
        $violations = $ruleEngine->validate($nonSplitAttempt);
        $this->assertNotEmpty($violations);
        $rules = collect($violations)->pluck('rule')->all();
        $this->assertContains('major_department_alignment', $rules);

        // Scenario B: Split schedule validation.
        // Split rows must follow the same relational integrity rules as normal rows.
        $splitAttempt = array_merge($nonSplitAttempt, [
            'split_group_id' => 'abc-123-xyz',
            'meeting_index' => 1,
        ]);
        $splitViolations = $ruleEngine->validate($splitAttempt);
        $this->assertNotEmpty($splitViolations);
        $splitRules = collect($splitViolations)->pluck('rule')->all();
        $this->assertContains('major_department_alignment', $splitRules);

        // Scenario C: Split schedule actual conflict validation.
        // If there is a real room conflict (occupied by another section), it should still flag it.
        $otherSection = Sections::create([
            'section_name' => 'IT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept1->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        Schedule::create([
            'term_id' => $term->id,
            'section_id' => $otherSection->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'day' => 'Monday',
            'start_time' => '11:00',
            'end_time' => '12:00',
            'mode' => 'on-site',
            'status' => 'draft',
            'department_id' => $dept1->id,
        ]);

        $splitConflictViolations = $ruleEngine->validate($splitAttempt);
        $this->assertNotEmpty($splitConflictViolations);
        $splitConflictRules = collect($splitConflictViolations)->pluck('rule')->all();
        $this->assertContains('room_conflict', $splitConflictRules);
        $this->assertContains('major_department_alignment', $splitConflictRules);
    }

    public function test_online_schedule_does_not_require_room_assignment()
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $dept = Departments::create([
            'department_name' => 'Department 1',
            'department_code' => 'DEPT1',
        ]);

        $course = Course::create([
            'course_code' => 'IT103',
            'course_name' => 'Integrated Applications Software',
            'lecture_hours' => 2,
            'lab_hours' => 1,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $violations = app(RuleEngine::class)->validate([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => null,
            'department_id' => $dept->id,
            'day' => 'Monday',
            'start_time' => '10:00',
            'end_time' => '13:00',
            'mode' => 'online',
        ]);

        $rules = collect($violations)->pluck('rule')->all();
        $this->assertNotContains('room_exists', $rules);
        $this->assertNotContains('room_type_match', $rules);
        $this->assertNotContains('delivery_room_alignment', $rules);
    }

    public function test_online_capacity_uses_department_configured_limit(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $dept = Departments::create([
            'department_name' => 'Department 1',
            'department_code' => 'DEPT1',
            'online_slot_limit' => 2,
        ]);
        $otherDept = Departments::create([
            'department_name' => 'Department 2',
            'department_code' => 'DEPT2',
        ]);

        $course = Course::create([
            'course_code' => 'IT104',
            'course_name' => 'Web Systems',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        $sections = collect(['IT 1A', 'IT 1B', 'IT 1C', 'IT 1D'])
            ->map(fn (string $name) => Sections::create([
                'section_name' => $name,
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $dept->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]));
        $otherSection = Sections::create([
            'section_name' => 'BA 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $otherDept->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        foreach ($sections->take(1) as $section) {
            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $section->id,
                'course_id' => $course->id,
                'room_id' => null,
                'department_id' => $dept->id,
                'day' => 'Monday',
                'start_time' => '07:00',
                'end_time' => '10:00',
                'mode' => 'online',
                'status' => 'draft',
            ]);
        }

        Schedule::create([
            'term_id' => $term->id,
            'section_id' => $otherSection->id,
            'course_id' => $course->id,
            'room_id' => null,
            'department_id' => $otherDept->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '10:00',
            'mode' => 'online',
            'status' => 'draft',
        ]);

        $secondAttempt = [
            'term_id' => $term->id,
            'section_id' => $sections[1]->id,
            'course_id' => $course->id,
            'room_id' => null,
            'department_id' => $dept->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '10:00',
            'mode' => 'online',
        ];

        $secondRules = collect(app(RuleEngine::class)->validate($secondAttempt))->pluck('rule')->all();
        $this->assertNotContains('online_capacity_conflict', $secondRules);

        Schedule::create(array_merge($secondAttempt, [
            'status' => 'draft',
        ]));

        $thirdRules = collect(app(RuleEngine::class)->validate(array_merge($secondAttempt, [
            'section_id' => $sections[2]->id,
        ])))->pluck('rule')->all();

        $this->assertContains('online_capacity_conflict', $thirdRules);
    }

    public function test_field_capacity_uses_department_configured_limit(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $dept = Departments::create([
            'department_name' => 'Department 1',
            'department_code' => 'DEPT1',
            'field_slot_limit' => 2,
        ]);
        $otherDept = Departments::create([
            'department_name' => 'Department 2',
            'department_code' => 'DEPT2',
        ]);

        $course = Course::create([
            'course_code' => 'PATH FIT 1',
            'course_name' => 'Physical Activities',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'minor',
            'room_type_required' => 'field',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => null,
            'status' => 'active',
        ]);

        $fieldRoom = Rooms::create([
            'room_code' => 'FIELD',
            'room_name' => 'Field',
            'room_type' => 'field',
            'status' => 'available',
            'department_id' => null,
            'max_concurrent_classes' => 3,
        ]);

        $sections = collect(['IT 1A', 'IT 1B', 'IT 1C', 'IT 1D'])
            ->map(fn (string $name) => Sections::create([
                'section_name' => $name,
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $dept->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]));
        $otherSection = Sections::create([
            'section_name' => 'BA 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $otherDept->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        foreach ($sections->take(1) as $section) {
            Schedule::create([
                'term_id' => $term->id,
                'section_id' => $section->id,
                'course_id' => $course->id,
                'room_id' => $fieldRoom->id,
                'department_id' => $dept->id,
                'day' => 'Monday',
                'start_time' => '07:00',
                'end_time' => '10:00',
                'mode' => 'field',
                'status' => 'draft',
            ]);
        }

        Schedule::create([
            'term_id' => $term->id,
            'section_id' => $otherSection->id,
            'course_id' => $course->id,
            'room_id' => $fieldRoom->id,
            'department_id' => $otherDept->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '10:00',
            'mode' => 'field',
            'status' => 'draft',
        ]);

        $secondAttempt = [
            'term_id' => $term->id,
            'section_id' => $sections[1]->id,
            'course_id' => $course->id,
            'room_id' => $fieldRoom->id,
            'department_id' => $dept->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '10:00',
            'mode' => 'field',
        ];

        $secondRules = collect(app(RuleEngine::class)->validate($secondAttempt))->pluck('rule')->all();
        $this->assertNotContains('room_capacity_conflict', $secondRules);

        Schedule::create(array_merge($secondAttempt, [
            'status' => 'draft',
        ]));

        $thirdRules = collect(app(RuleEngine::class)->validate(array_merge($secondAttempt, [
            'section_id' => $sections[2]->id,
        ])))->pluck('rule')->all();

        $this->assertContains('room_capacity_conflict', $thirdRules);
    }

    public function test_only_gec_service_subjects_require_cas_faculty(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $cas = Departments::create([
            'department_name' => 'College of Arts and Sciences',
            'department_code' => 'CAS',
        ]);
        $cit = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        $citFaculty = Faculty::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'department_id' => $cit->id,
            'status' => 'active',
        ]);
        $casFaculty = Faculty::create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'department_id' => $cas->id,
            'status' => 'active',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cit->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $lectureRoom = Rooms::create([
            'room_code' => 'IT105',
            'room_name' => 'IT Room 105',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $cit->id,
        ]);
        $fieldRoom = Rooms::create([
            'room_code' => 'FIELD',
            'room_name' => 'Field',
            'room_type' => 'field',
            'status' => 'available',
            'department_id' => null,
            'max_concurrent_classes' => 3,
        ]);

        $gec = Course::create([
            'course_code' => 'GEC 1',
            'course_name' => 'Understanding the Self',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cas->id,
            'status' => 'active',
        ]);
        $pathfit = Course::create([
            'course_code' => 'PATH FIT 1',
            'course_name' => 'Movement Competency Training',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'minor',
            'room_type_required' => 'field',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cas->id,
            'status' => 'active',
        ]);
        $nstp = Course::create([
            'course_code' => 'NSTP 1',
            'course_name' => 'National Service Training Program',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'field',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cas->id,
            'status' => 'active',
        ]);

        $ruleEngine = app(RuleEngine::class);
        $base = [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'department_id' => $cit->id,
            'day' => 'Monday',
            'start_time' => '10:00',
            'end_time' => '12:00',
        ];

        $gecRules = collect($ruleEngine->validate(array_merge($base, [
            'course_id' => $gec->id,
            'faculty_id' => $citFaculty->id,
            'room_id' => $lectureRoom->id,
            'mode' => 'on-site',
            'end_time' => '13:00',
        ])))->pluck('rule')->all();
        $this->assertContains('service_subject_faculty_department_alignment', $gecRules);

        $gecWithCasFacultyRules = collect($ruleEngine->validate(array_merge($base, [
            'course_id' => $gec->id,
            'faculty_id' => $casFaculty->id,
            'room_id' => $lectureRoom->id,
            'mode' => 'on-site',
            'end_time' => '13:00',
        ])))->pluck('rule')->all();
        $this->assertNotContains('service_subject_faculty_department_alignment', $gecWithCasFacultyRules);

        foreach ([$pathfit, $nstp] as $course) {
            $rules = collect($ruleEngine->validate(array_merge($base, [
                'course_id' => $course->id,
                'faculty_id' => $citFaculty->id,
                'room_id' => $fieldRoom->id,
                'mode' => 'field',
            ])))->pluck('rule')->all();

            $this->assertNotContains('service_subject_faculty_department_alignment', $rules);
            $this->assertNotContains('faculty_department_alignment', $rules);
        }
    }

    public function test_vpaa_course_teaching_assignment_controls_allowed_faculty_department(): void
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $cas = Departments::create([
            'department_name' => 'College of Arts and Sciences',
            'department_code' => 'CAS',
        ]);
        $cit = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        $casFaculty = Faculty::create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'department_id' => $cas->id,
            'status' => 'active',
        ]);
        $citFaculty = Faculty::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'department_id' => $cit->id,
            'status' => 'active',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cit->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $room = Rooms::create([
            'room_code' => 'IT105',
            'room_name' => 'IT Room 105',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $cit->id,
        ]);

        $course = Course::create([
            'course_code' => 'GEC 2',
            'course_name' => 'Readings in Philippine History',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $cas->id,
            'status' => 'active',
        ]);

        CourseTeachingAssignment::create([
            'course_id' => $course->id,
            'department_id' => $cit->id,
        ]);
        SchedulingPolicy::clearCourseTeachingAssignmentCache();

        $base = [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $cit->id,
            'day' => 'Monday',
            'start_time' => '10:00',
            'end_time' => '13:00',
            'mode' => 'on-site',
        ];

        $casRules = collect(app(RuleEngine::class)->validate(array_merge($base, [
            'faculty_id' => $casFaculty->id,
        ])))->pluck('rule')->all();
        $this->assertContains('service_subject_faculty_department_alignment', $casRules);

        $citRules = collect(app(RuleEngine::class)->validate(array_merge($base, [
            'faculty_id' => $citFaculty->id,
        ])))->pluck('rule')->all();
        $this->assertNotContains('service_subject_faculty_department_alignment', $citRules);
        $this->assertNotContains('faculty_department_alignment', $citRules);
    }
}
