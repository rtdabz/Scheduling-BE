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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A class placed while a setting allowed it can stop satisfying that setting
 * later: a Required Day is set for its course, or its room is taken out of
 * service. Staffing that class must not be refused for it -- choosing an
 * instructor does not move the class. Only the instructor's own rules apply,
 * while moving the class still answers to every placement rule.
 */
class InstructorAssignmentIgnoresPlacementDriftTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_class_picker_assigns_an_instructor_after_a_required_day_is_set(): void
    {
        $fixture = $this->fixture();
        $this->requireDay($fixture, 'Monday');

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$fixture['placed']->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertOk();

        $this->assertDatabaseHas('schedules', ['id' => $fixture['placed']->id, 'faculty_id' => $fixture['faculty']->id]);
    }

    public function test_bulk_assignment_ignores_a_required_day_set_after_placement(): void
    {
        $fixture = $this->fixture();
        $this->requireDay($fixture, 'Monday');

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => [$fixture['placed']->id], 'faculty_id' => $fixture['faculty']->id]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedules', ['id' => $fixture['placed']->id, 'faculty_id' => $fixture['faculty']->id]);
    }

    public function test_the_instructor_assignment_page_ignores_a_required_day_set_after_placement(): void
    {
        $fixture = $this->fixture();
        $this->requireDay($fixture, 'Monday');

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$fixture['placed']->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertOk();

        $this->assertDatabaseHas('schedules', ['id' => $fixture['placed']->id, 'faculty_id' => $fixture['faculty']->id]);
    }

    public function test_an_instructor_is_assigned_to_a_class_whose_room_left_service(): void
    {
        $fixture = $this->fixture();
        $fixture['room']->update(['status' => 'not available']);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$fixture['placed']->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertOk();
    }

    public function test_an_instructor_is_removed_from_a_drifted_class(): void
    {
        $fixture = $this->fixture();
        $fixture['placed']->update(['faculty_id' => $fixture['faculty']->id]);
        $this->requireDay($fixture, 'Monday');

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$fixture['placed']->id}", ['faculty_id' => null])
            ->assertOk();

        $this->assertDatabaseHas('schedules', ['id' => $fixture['placed']->id, 'faculty_id' => null]);
    }

    public function test_the_instructors_own_clash_is_still_refused_on_a_drifted_class(): void
    {
        $fixture = $this->fixture();
        $this->requireDay($fixture, 'Monday');
        // The same instructor already teaches another section at that hour.
        Schedule::create([
            ...$this->row($fixture),
            'section_id' => $fixture['otherSection']->id,
            'room_id' => $fixture['otherRoom']->id,
            'faculty_id' => $fixture['faculty']->id,
        ]);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$fixture['placed']->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'faculty_conflict')
            ->assertJsonCount(1, 'violations');
    }

    public function test_moving_a_drifted_class_still_answers_to_its_required_day(): void
    {
        $fixture = $this->fixture();
        $fixture['placed']->update(['status' => 'draft']);
        $this->requireDay($fixture, 'Monday');

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$fixture['placed']->id}", [
                'day' => 'Wednesday',
                'start_time' => '08:00',
                'end_time' => '09:00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'forced_course_day');
    }

    /** @param array<string, mixed> $fixture */
    private function requireDay(array $fixture, string $day): void
    {
        DB::table('department_forced_course_days')->insert([
            'department_id' => $fixture['department']->id,
            'course_id' => $fixture['course']->id,
            'day' => $day,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function row(array $fixture): array
    {
        return [
            'semester_id' => $fixture['semester']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $fixture['course']->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'faculty_id' => null,
            'day' => 'Tuesday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Drift Dept', 'department_code' => 'DRF']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'DRFP', 'name' => 'Drift Program']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $section = fn (string $name) => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        $fixture = [
            'department' => $department,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'DRF101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'otherRoom' => Rooms::create(['room_code' => 'DRF102', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create([
                'course_code' => 'DRF101',
                'course_name' => 'Drift Course',
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
            'section' => $section('DRF-1A'),
            'otherSection' => $section('DRF-1B'),
            'faculty' => Faculty::create([
                'first_name' => 'Drift',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
        // Placed on Tuesday while no Required Day existed.
        $fixture['placed'] = Schedule::create($this->row($fixture));

        return $fixture;
    }
}
