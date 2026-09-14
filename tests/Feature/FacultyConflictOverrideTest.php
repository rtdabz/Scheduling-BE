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
 * An instructor may be assigned over their own conflict -- double-booked, or
 * outside a part-timer's availability -- when the user chooses to. Anything else
 * still refuses, and the override only stands while the meeting keeps the
 * instructor, day and time it was approved with.
 */
class FacultyConflictOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clash_is_refused_but_marked_as_overridable(): void
    {
        [$fixture, , $clashing] = $this->clash();

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => [$clashing->id], 'faculty_id' => $fixture['faculty']->id]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('can_override_conflicts', true)
            ->assertJsonPath('violations.0.rule', 'faculty_conflict')
            ->assertJsonPath('violations.0.overridable', true);

        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);
    }

    public function test_overriding_saves_the_clash_and_marks_both_meetings(): void
    {
        [$fixture, $taught, $clashing] = $this->clash();

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => [$clashing->id],
                    'faculty_id' => $fixture['faculty']->id,
                    'override_conflicts' => true,
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedules', [
            'id' => $clashing->id,
            'faculty_id' => $fixture['faculty']->id,
            'faculty_conflict_override' => true,
        ]);
        $this->assertDatabaseHas('schedules', ['id' => $taught->id, 'faculty_conflict_override' => true]);
    }

    public function test_the_single_class_picker_can_override_too(): void
    {
        [$fixture, , $clashing] = $this->clash();

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$clashing->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(422)
            ->assertJsonPath('can_override_conflicts', true);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$clashing->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'override_conflicts' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedules', [
            'id' => $clashing->id,
            'faculty_id' => $fixture['faculty']->id,
            'faculty_conflict_override' => true,
        ]);
    }

    public function test_the_instructor_assignment_page_can_override_too(): void
    {
        [$fixture, $taught, $clashing] = $this->clash();

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$clashing->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(422)
            ->assertJsonPath('can_override_conflicts', true);

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$clashing->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'override_conflicts' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedules', [
            'id' => $clashing->id,
            'faculty_id' => $fixture['faculty']->id,
            'faculty_conflict_override' => true,
        ]);
        $this->assertDatabaseHas('schedules', ['id' => $taught->id, 'faculty_conflict_override' => true]);
    }

    public function test_a_conflict_that_is_not_the_instructors_own_cannot_be_overridden(): void
    {
        [$fixture, , $clashing] = $this->clash();
        $elsewhere = Departments::create(['department_name' => 'Elsewhere', 'department_code' => 'ELS']);
        $outsider = Faculty::create([
            'first_name' => 'Other',
            'last_name' => 'College',
            'employment_type' => 'full-time',
            'department_id' => $elsewhere->id,
            'status' => 'active',
        ]);

        // A major taught from another department is an alignment refusal, which
        // the override flag must not wave through.
        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => [$clashing->id],
                    'faculty_id' => $outsider->id,
                    'override_conflicts' => true,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('can_override_conflicts', false);

        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);
    }

    public function test_an_overridden_meeting_saves_again_without_raising_the_clash(): void
    {
        [$fixture, $taught, $clashing] = $this->clash();
        Schedule::query()->whereKey($clashing->id)->update(['faculty_id' => $fixture['faculty']->id]);
        Schedule::query()->whereIn('id', [$taught->id, $clashing->id])->update(['faculty_conflict_override' => true]);

        // Re-sent the way a relocate carries it: same instructor, day and time.
        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$clashing->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertOk();

        $this->assertTrue($clashing->refresh()->faculty_conflict_override);
    }

    public function test_moving_or_reassigning_a_meeting_clears_its_override(): void
    {
        [$fixture, , $clashing] = $this->clash();
        Schedule::query()->whereKey($clashing->id)->update([
            'faculty_id' => $fixture['faculty']->id,
            'faculty_conflict_override' => true,
        ]);

        $clashing->refresh()->update(['start_time' => '08:00']);
        $this->assertTrue($clashing->refresh()->faculty_conflict_override, 'The same time written differently is not a move.');

        $clashing->update(['day' => 'Friday']);
        $this->assertFalse($clashing->refresh()->faculty_conflict_override);
    }

    /** @return array{0: array<string, mixed>, 1: Schedule, 2: Schedule} */
    private function clash(): array
    {
        $fixture = $this->fixture();
        $taught = $this->schedule($fixture, ['faculty_id' => $fixture['faculty']->id]);
        $clashing = $this->schedule($fixture, [
            'section_id' => $fixture['otherSection']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);

        return [$fixture, $taught, $clashing];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Override Dept', 'department_code' => 'OVR']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'OVRP', 'name' => 'Override Program']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'department' => $department,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'OVR101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'otherRoom' => Rooms::create(['room_code' => 'OVR102', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create([
                'course_code' => 'OVR101',
                'course_name' => 'Override Course',
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
                'section_name' => 'OVR-1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'program_id' => $program->id,
                'semester_id' => $semester->id,
                'status' => 'active',
            ]),
            'otherSection' => Sections::create([
                'section_name' => 'OVR-1B',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'program_id' => $program->id,
                'semester_id' => $semester->id,
                'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'Override',
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
