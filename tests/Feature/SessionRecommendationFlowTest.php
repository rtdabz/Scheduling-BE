<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
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
 * A course's session is a soft preference: when the session is full the
 * course lands elsewhere and the run still succeeds. The preview must then
 * recommend a session that has space, as an adjustment the wizard can apply.
 */
class SessionRecommendationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function test_an_unmet_morning_preference_recommends_a_session_with_room(): void
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'College of Business Administration', 'department_code' => 'CBA']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSBA', 'name' => 'Business Administration']);
        $curriculum = Curriculum::create(['name' => 'BSBA', 'department_id' => $department->id, 'code' => 'CMO17', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $course = $this->course($department, $program, 'BAC 11');
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $room = Rooms::create(['room_code' => 'CBA 11', 'building' => 'CBA', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        $section = $this->section('BSBA 1A', $department, $program, $curriculum, $semester);
        // Another section, outside this run, holds the only room every morning.
        $other = $this->section('BSBA 2A', $department, $program, $curriculum, $semester, '2');
        $otherCourse = $this->course($department, $program, 'BAC 21');
        foreach (self::WEEK as $day) {
            Schedule::create([
                'semester_id' => $semester->id, 'section_id' => $other->id, 'course_id' => $otherCourse->id,
                'department_id' => $department->id, 'room_id' => $room->id, 'day' => $day,
                'start_time' => '07:00', 'end_time' => '11:30', 'mode' => 'on-site', 'status' => 'draft',
            ]);
        }

        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $response = $this->actingAs($user)->postJson('/api/schedule-recommendations/year-level-preview', [
            'semester_id' => (int) $semester->id,
            'department_id' => (int) $department->id,
            'year_level' => 1,
            'section_configs' => [[
                'section_id' => (int) $section->id,
                'course_ids' => [(int) $course->id],
                'delivery_modes_by_course_id' => [(int) $course->id => 'on-site'],
                'time_preferences_by_course_id' => [(int) $course->id => 'morning'],
            ]],
        ]);

        $response->assertOk();
        $session = collect($response->json('recommendations'))
            ->firstWhere('id', sprintf('session-course-%d-%d-afternoon', $section->id, $course->id));

        $this->assertNotNull($session, 'Expected an actionable afternoon recommendation.');
        $this->assertSame('Put BAC 11 in the Afternoon Session', $session['title']);
        $this->assertSame([[
            'type' => 'set_time_preference',
            'section_id' => (int) $section->id,
            'course_id' => (int) $course->id,
            'value' => 'afternoon',
            'section_name' => 'BSBA 1A',
            'course_code' => 'BAC 11',
        ]], $session['adjustments']);

        // The full morning is never offered back.
        $this->assertNull(collect($response->json('recommendations'))
            ->firstWhere('id', sprintf('session-course-%d-%d-morning', $section->id, $course->id)));
    }

    private function course(Departments $department, Program $program, string $code): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => "Course {$code}", 'lecture_hours' => 3, 'lab_hours' => 0,
            'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1',
            'semester' => '1st', 'department_id' => $department->id, 'program_id' => $program->id, 'status' => 'active',
        ]);
    }

    private function section(string $name, Departments $department, Program $program, Curriculum $curriculum, Semester $semester, string $yearLevel = '1'): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => $yearLevel, 'semester' => '1st', 'department_id' => $department->id,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
    }
}
