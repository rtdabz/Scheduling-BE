<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleEngineSplitValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_schedule_only_validates_conflicts_and_ignores_relational_integrity()
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
        // Relational integrity rules (e.g. major_department_alignment) should be skipped/ignored.
        $splitAttempt = array_merge($nonSplitAttempt, [
            'split_group_id' => 'abc-123-xyz',
            'meeting_index' => 1,
        ]);
        $splitViolations = $ruleEngine->validate($splitAttempt);
        $this->assertEmpty($splitViolations);

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
        $this->assertNotContains('major_department_alignment', $splitConflictRules);
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
}
