<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/schedules feeds the Master Calendar, which asks for up to a thousand
 * meetings at once. Departments store their logo inline as base64, so the nested
 * department has to stay limited to what a timetable actually labels.
 */
class ScheduleIndexPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_department_omits_the_inline_logo(): void
    {
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
            'logo' => 'data:image/png;base64,'.str_repeat('A', 4096),
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $section = Sections::create([
            'section_name' => 'BSIT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Introduction to Computing',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        $room = Rooms::create(['room_code' => 'CIT 101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ]);

        $vpaa = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa']), ['schedule.view']);

        $row = $this->actingAs($vpaa)
            ->getJson("/api/schedules?semester_id={$semester->id}&per_page=1000")
            ->assertOk()
            ->assertJsonCount(1)
            ->json('0');

        $this->assertSame(
            ['department_code' => 'CIT', 'department_name' => 'Information Technology', 'id' => $department->id],
            collect($row['department'])->sortKeys()->all(),
        );
        $this->assertSame('IT 101', $row['course']['course_code']);
    }
}
