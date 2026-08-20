<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `InstructorAssignmentController` has always refused to assign an instructor
 * before VPAA approval, but the schedule write endpoints accepted a faculty_id at
 * any status — so the rule only held because the client happened to hide the
 * controls. Withdrawal now releases assignments, which is pointless if the next
 * request can put one straight back on a `revision` row.
 */
class InstructorAssignmentStageGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_is_refused_before_vpaa_approval(): void
    {
        $fixture = $this->fixture();
        $schedule = $this->schedule($fixture, ['status' => 'revision']);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$schedule->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Instructor assignment is available only after VPAA approval.');

        $this->assertNull($schedule->refresh()->faculty_id);
    }

    public function test_assignment_is_allowed_once_the_schedule_is_approved(): void
    {
        $fixture = $this->fixture();
        $schedule = $this->schedule($fixture, ['status' => 'approved']);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$schedule->id}", ['faculty_id' => $fixture['faculty']->id])
            ->assertOk();

        $this->assertSame($fixture['faculty']->id, $schedule->refresh()->faculty_id);
    }

    public function test_a_finalized_schedule_cannot_be_reassigned(): void
    {
        $fixture = $this->fixture();
        $other = Faculty::create([
            'first_name' => 'Other',
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'max_units' => 18,
            'department_id' => $fixture['department']->id,
            'status' => 'active',
        ]);
        $schedule = $this->schedule($fixture, [
            'status' => 'finalized',
            'faculty_id' => $fixture['faculty']->id,
        ]);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$schedule->id}", ['faculty_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A finalized schedule cannot be reassigned.');

        $this->assertSame($fixture['faculty']->id, $schedule->refresh()->faculty_id);
    }

    public function test_relocating_a_row_may_carry_its_existing_instructor(): void
    {
        $fixture = $this->fixture();
        $schedule = $this->schedule($fixture, [
            'status' => 'revision',
            'faculty_id' => $fixture['faculty']->id,
        ]);

        // Re-sending the value the row already holds is what the plotting save
        // does on every relocate, so it must not be treated as an assignment.
        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$schedule->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'day' => 'Tuesday',
            ])
            ->assertOk();

        $this->assertSame('Tuesday', $schedule->refresh()->day);
    }

    public function test_clearing_an_instructor_is_allowed_at_any_status(): void
    {
        $fixture = $this->fixture();
        $schedule = $this->schedule($fixture, [
            'status' => 'revision',
            'faculty_id' => $fixture['faculty']->id,
        ]);

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$schedule->id}", ['faculty_id' => null])
            ->assertOk();

        $this->assertNull($schedule->refresh()->faculty_id);
    }

    public function test_batch_faculty_refuses_a_row_outside_the_assignment_stage(): void
    {
        $fixture = $this->fixture();
        $assignable = $this->schedule($fixture, ['status' => 'faculty_assignment']);
        $notAssignable = $this->schedule($fixture, [
            'status' => 'revision',
            'day' => 'Wednesday',
        ]);

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => [$assignable->id, $notAssignable->id],
                    'faculty_id' => $fixture['faculty']->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'instructor_assignment_stage')
            ->assertJsonPath('violations.0.schedule_id', $notAssignable->id);

        // Nothing is written when any row in the set is refused.
        $this->assertNull($assignable->refresh()->faculty_id);
        $this->assertNull($notAssignable->refresh()->faculty_id);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Stage Dept', 'department_code' => 'STG']);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'department' => $department,
            'term' => $term,
            'room' => Rooms::create([
                'room_code' => 'STG101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]),
            'course' => Course::create([
                'course_code' => 'STG101',
                'course_name' => 'Stage Course',
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
                'section_name' => 'STG-1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'Stage',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'max_units' => 18,
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'user' => User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function schedule(array $fixture, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $fixture['course']->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'faculty_id' => null,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
        ], $overrides));
    }
}
