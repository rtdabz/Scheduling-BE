<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferredPatternFallbackRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;
    private Sections $section;
    private Course $course;
    private Rooms $lectureRoom;
    private CspSolver $solver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Departments::create([
            'department_name' => 'College of Computer Studies',
            'department_code' => 'CCS',
            'status' => 'active',
        ]);

        $semester = \App\Models\Semester::create([
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
            'semester_id' => $semester->id,
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

        $this->solver = app(CspSolver::class);
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
        $this->assertSame($schedules[0]['start_time'], $schedules[1]['start_time']);
        $this->assertSame($schedules[0]['end_time'], $schedules[1]['end_time']);
    }

    public function test_recommends_alternative_split_pattern_when_preferred_pattern_is_occupied(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'semester_id' => $this->section->semester_id,
            'status' => 'active',
        ]);

        // Occupy the lecture room on Monday and Wednesday for all daytime slots
        foreach (['Monday', 'Wednesday'] as $day) {
            Schedule::create([
                'section_id' => $otherSection->id,
                'course_id' => $this->course->id,
                'room_id' => $this->lectureRoom->id,
                'department_id' => $this->department->id,
                'semester_id' => $this->section->semester_id,
                'day' => $day,
                // Block the whole configured day, not a hard-coded window.
                'start_time' => SchedulingPolicy::openingTime(),
                'end_time' => SchedulingPolicy::closingTime(),
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

    public function test_does_not_fall_back_to_a_single_session_when_split_patterns_are_occupied(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'semester_id' => $this->section->semester_id,
            'status' => 'active',
        ]);

        // Occupy the lecture room on all weekdays except Friday
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday'] as $day) {
            Schedule::create([
                'section_id' => $otherSection->id,
                'course_id' => $this->course->id,
                'room_id' => $this->lectureRoom->id,
                'department_id' => $this->department->id,
                'semester_id' => $this->section->semester_id,
                'day' => $day,
                // Block the whole configured day, not a hard-coded window.
                'start_time' => SchedulingPolicy::openingTime(),
                'end_time' => SchedulingPolicy::closingTime(),
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
        $this->assertCount(2, $schedules, 'A configured Split must remain two meetings; Regular Meeting is a separate recommendation.');
        $this->assertFalse((bool) ($schedules[0]['split_session_fallback'] ?? false));

        $totalMinutes = array_reduce($schedules, static function (int $total, array $schedule): int {
            return $total + (int) ((strtotime($schedule['end_time']) - strtotime($schedule['start_time'])) / 60);
        }, 0);
        $this->assertEquals(180, $totalMinutes, 'Split meetings must preserve the full 3 hours duration');
    }

    public function test_automatic_split_session_does_not_fall_back_to_one_day_when_both_patterns_are_occupied(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'semester_id' => $this->section->semester_id,
            'status' => 'active',
        ]);

        // Block every day used by the automatic split-session domain.
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday'] as $day) {
            Schedule::create([
                'section_id' => $otherSection->id,
                'course_id' => $this->course->id,
                'room_id' => $this->lectureRoom->id,
                'department_id' => $this->department->id,
                'semester_id' => $this->section->semester_id,
                'day' => $day,
                // Block the whole configured day, not a hard-coded window.
                'start_time' => SchedulingPolicy::openingTime(),
                'end_time' => SchedulingPolicy::closingTime(),
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
            // No preferred pattern means the generator chooses MW or TTh.
            'preferred_patterns' => [],
            'delivery_modes_by_course_id' => [],
            'seed' => 12345,
            'max_solutions' => 1,
        ]);

        $this->assertNotEmpty($solutions);
        $schedules = $solutions[0]['schedules'];
        $this->assertCount(2, $schedules, 'Automatic Split Session must remain two meetings when a split candidate is available.');
        foreach ($schedules as $schedule) {
            $this->assertFalse((bool) ($schedule['split_session_fallback'] ?? false));
        }
    }

    public function test_falls_back_to_online_when_all_physical_rooms_are_occupied(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 2B',
            'department_id' => $this->department->id,
            'year_level' => '2',
            'semester' => '1st',
            'semester_id' => $this->section->semester_id,
            'status' => 'active',
        ]);

        // Occupy all physical days (Mon - Sat) in the physical lecture room
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            Schedule::create([
                'section_id' => $otherSection->id,
                'course_id' => $this->course->id,
                'room_id' => $this->lectureRoom->id,
                'department_id' => $this->department->id,
                'semester_id' => $this->section->semester_id,
                'day' => $day,
                // Block the whole configured day, not a hard-coded window.
                'start_time' => SchedulingPolicy::openingTime(),
                'end_time' => SchedulingPolicy::closingTime(),
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
