<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Clear all instructors" has to be able to cover the whole department. Clearing
 * one section left every other section's assignments, and so each instructor's
 * load, in place.
 */
class InstructorAssignmentClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_clearing_several_sections_releases_every_assignment_in_them(): void
    {
        $fixture = $this->fixture();
        $first = $this->schedule($fixture, ['faculty_id' => $fixture['faculty']->id]);
        $second = $this->schedule($fixture, [
            'section_id' => $fixture['otherSection']->id,
            'room_id' => $fixture['otherRoom']->id,
            'day' => 'Tuesday',
            'faculty_id' => $fixture['faculty']->id,
        ]);

        $this->actingAs($fixture['user'])
            ->postJson('/api/instructor-assignments/clear', [
                'section_ids' => [$fixture['section']->id, $fixture['otherSection']->id],
            ])
            ->assertOk()
            ->assertJsonPath('schedules_updated', 2);

        $this->assertDatabaseHas('schedules', ['id' => $first->id, 'faculty_id' => null]);
        $this->assertDatabaseHas('schedules', ['id' => $second->id, 'faculty_id' => null]);
        $this->assertDatabaseHas('scheduling_audit_logs', [
            'action' => 'instructor_assignment_released',
            'section_id' => null,
        ]);
    }

    public function test_clearing_one_section_leaves_the_others_assigned(): void
    {
        $fixture = $this->fixture();
        $cleared = $this->schedule($fixture, ['faculty_id' => $fixture['faculty']->id]);
        $kept = $this->schedule($fixture, [
            'section_id' => $fixture['otherSection']->id,
            'room_id' => $fixture['otherRoom']->id,
            'day' => 'Tuesday',
            'faculty_id' => $fixture['faculty']->id,
        ]);

        $this->actingAs($fixture['user'])
            ->deleteJson("/api/instructor-assignments/sections/{$fixture['section']->id}")
            ->assertOk();

        $this->assertDatabaseHas('schedules', ['id' => $cleared->id, 'faculty_id' => null]);
        $this->assertDatabaseHas('schedules', ['id' => $kept->id, 'faculty_id' => $fixture['faculty']->id]);
    }

    public function test_a_section_outside_the_active_semester_is_refused(): void
    {
        $fixture = $this->fixture();
        $this->schedule($fixture, ['faculty_id' => $fixture['faculty']->id]);
        $fixture['otherSection']->update(['semester_id' => Semester::create([
            'academic_year' => '2025-2026',
            'semester' => '2nd',
            'is_active' => false,
            'is_enabled' => true,
        ])->id]);

        $this->actingAs($fixture['user'])
            ->postJson('/api/instructor-assignments/clear', [
                'section_ids' => [$fixture['section']->id, $fixture['otherSection']->id],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('schedules', ['faculty_id' => null]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Clear Dept', 'department_code' => 'CLR']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'CLRP', 'name' => 'Clear Program']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'department' => $department,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'CLR101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'otherRoom' => Rooms::create(['room_code' => 'CLR102', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create([
                'course_code' => 'CLR101',
                'course_name' => 'Clear Course',
                'lecture_hours' => 1,
                'lab_hours' => 0,
                'units' => 1,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'section' => Sections::create([
                'section_name' => 'CLR-1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'program_id' => $program->id,
                'semester_id' => $semester->id,
                'status' => 'active',
            ]),
            'otherSection' => Sections::create([
                'section_name' => 'CLR-1B',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'program_id' => $program->id,
                'semester_id' => $semester->id,
                'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'Clear',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function schedule(array $fixture, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $fixture['course']->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'faculty_id' => null,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ], $overrides));
    }
}
