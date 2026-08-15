<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Services\Scheduling\CSPSolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferredPatternFallbackRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;
    private Sections $section;
    private Course $course;
    private Rooms $lectureRoom;
    private CSPSolver $solver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Departments::create([
            'department_name' => 'College of Computer Studies',
            'department_code' => 'CCS',
            'status' => 'active',
        ]);

        $term = \App\Models\Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $this->section = Sections::create([
            'section_name' => 'BSIT 2A',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $this->course = Course::create([
            'course_code' => 'GEC 3',
            'course_name' => 'Purposive Communication',
            'department_id' => $this->department->id,
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'year_level' => '2',
            'semester' => '1st',
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'status' => 'active',
        ]);

        $this->lectureRoom = Rooms::create([
            'room_code' => 'IT 201',
            'building' => 'IT Building',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $this->department->id,
        ]);

        $this->solver = app(CSPSolver::class);
    }

    public function test_uses_preferred_pattern_when_vacant(): void
    {
        $solutions = $this->solver->solveRankedFromSchema([
            'section_id' => (int) $this->section->id,
            'course_ids' => [(int) $this->course->id],
            'mode' => 'on-site',
            'is_hybrid' => false,
            'balanced_split_course_ids' => [(int) $this->course->id],
            'preferred_patterns' => [(int) $this->course->id => 'MW'],
            'delivery_modes_by_course_id' => [],
            'seed' => 12345,
            'max_solutions' => 1,
        ]);

        $this->assertNotEmpty($solutions);
        $schedules = $solutions[0]['schedules'];
        $this->assertCount(2, $schedules);

        $days = array_column($schedules, 'day');
        sort($days);
        $this->assertEquals(['Monday', 'Wednesday'], $days);
        $this->assertEquals('on-site', $schedules[0]['mode']);
        $this->assertEquals('on-site', $schedules[1]['mode']);
        $this->assertEquals($this->lectureRoom->id, $schedules[0]['room_id']);
        $this->assertEquals($this->lectureRoom->id, $schedules[1]['room_id']);
    }

    public function test_recommends_alternative_split_pattern_when_preferred_pattern_is_occupied(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'term_id' => $this->section->term_id,
            'status' => 'active',
        ]);

        // Occupy the lecture room on Monday and Wednesday for all daytime slots
        foreach (['Monday', 'Wednesday'] as $day) {
            Schedule::create([
                'section_id' => $otherSection->id,
                'course_id' => $this->course->id,
                'room_id' => $this->lectureRoom->id,
                'department_id' => $this->department->id,
                'term_id' => $this->section->term_id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }

        $solutions = $this->solver->solveRankedFromSchema([
            'section_id' => (int) $this->section->id,
            'course_ids' => [(int) $this->course->id],
            'mode' => 'on-site',
            'is_hybrid' => false,
            'balanced_split_course_ids' => [(int) $this->course->id],
            'preferred_patterns' => [(int) $this->course->id => 'MW'],
            'delivery_modes_by_course_id' => [],
            'seed' => 12345,
            'max_solutions' => 1,
        ]);

        $this->assertNotEmpty($solutions);
        $schedules = $solutions[0]['schedules'];
        $this->assertNotEmpty($schedules);

        $days = array_unique(array_column($schedules, 'day'));
        // Preferred pattern MW was fully blocked, so it should not be on Monday or Wednesday
        $this->assertNotContains('Monday', $days);
        $this->assertNotContains('Wednesday', $days);

        // Total duration should still equal 3 hours (6 slots / 180 minutes)
        $totalMinutes = 0;
        foreach ($schedules as $row) {
            $start = strtotime($row['start_time']);
            $end = strtotime($row['end_time']);
            $totalMinutes += ($end - $start) / 60;
        }
        $this->assertEquals(180, $totalMinutes, 'Required 3 hours duration must be preserved');
    }

    public function test_recommends_single_session_when_split_patterns_are_occupied(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'term_id' => $this->section->term_id,
            'status' => 'active',
        ]);

        // Occupy the lecture room on all weekdays except Friday
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday'] as $day) {
            Schedule::create([
                'section_id' => $otherSection->id,
                'course_id' => $this->course->id,
                'room_id' => $this->lectureRoom->id,
                'department_id' => $this->department->id,
                'term_id' => $this->section->term_id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }

        $solutions = $this->solver->solveRankedFromSchema([
            'section_id' => (int) $this->section->id,
            'course_ids' => [(int) $this->course->id],
            'mode' => 'on-site',
            'is_hybrid' => false,
            'balanced_split_course_ids' => [(int) $this->course->id],
            'preferred_patterns' => [(int) $this->course->id => 'MW'],
            'delivery_modes_by_course_id' => [],
            'seed' => 12345,
            'max_solutions' => 1,
        ]);

        $this->assertNotEmpty($solutions);
        $schedules = $solutions[0]['schedules'];
        $this->assertCount(1, $schedules, 'Should recommend a single 3-hour session on the available day');
        $this->assertEquals('Friday', $schedules[0]['day']);
        $this->assertEquals('on-site', $schedules[0]['mode']);

        $start = strtotime($schedules[0]['start_time']);
        $end = strtotime($schedules[0]['end_time']);
        $this->assertEquals(180, ($end - $start) / 60, 'Single session must have full 3 hours duration');
    }

    public function test_falls_back_to_online_when_all_physical_rooms_are_occupied(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'term_id' => $this->section->term_id,
            'status' => 'active',
        ]);

        // Occupy all physical days (Mon - Sat) in the physical lecture room
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            Schedule::create([
                'section_id' => $otherSection->id,
                'course_id' => $this->course->id,
                'room_id' => $this->lectureRoom->id,
                'department_id' => $this->department->id,
                'term_id' => $this->section->term_id,
                'day' => $day,
                'start_time' => '07:00:00',
                'end_time' => '19:00:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]);
        }

        $solutions = $this->solver->solveRankedFromSchema([
            'section_id' => (int) $this->section->id,
            'course_ids' => [(int) $this->course->id],
            'mode' => 'on-site',
            'is_hybrid' => false,
            'balanced_split_course_ids' => [(int) $this->course->id],
            'preferred_patterns' => [(int) $this->course->id => 'MW'],
            'delivery_modes_by_course_id' => [],
            'seed' => 12345,
            'max_solutions' => 1,
        ]);

        $this->assertNotEmpty($solutions);
        $schedules = $solutions[0]['schedules'];
        $this->assertNotEmpty($schedules);

        foreach ($schedules as $row) {
            $this->assertEquals('online', $row['mode'], 'Should fallback to online mode when physical rooms are fully booked');
            $this->assertNull($row['room_id'], 'Online schedule should not have a physical room assignment');
        }

        $totalMinutes = 0;
        foreach ($schedules as $row) {
            $start = strtotime($row['start_time']);
            $end = strtotime($row['end_time']);
            $totalMinutes += ($end - $start) / 60;
        }
        $this->assertEquals(180, $totalMinutes, 'Online fallback must preserve 3 hours duration');
    }
}
