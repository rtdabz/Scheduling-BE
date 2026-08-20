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
 * Guards the fix for audit finding #9: Auto-Assign issued one PUT per schedule
 * with nothing spanning them, so a failure partway through left the earlier
 * assignments committed while the user was told the operation failed.
 */
class ScheduleBatchFacultyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_every_schedule_in_one_request(): void
    {
        $fixture = $this->fixture();

        $monday = $this->schedule($fixture, ['day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00']);
        $tuesday = $this->schedule($fixture, ['day' => 'Tuesday', 'start_time' => '08:00', 'end_time' => '09:00']);

        $response = $this->actingAs($fixture['user'])->patchJson('/api/schedules/batch-faculty', [
            'assignments' => [[
                'schedule_ids' => [$monday->id, $tuesday->id],
                'faculty_id' => $fixture['faculty']->id,
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('schedules_updated', 2)
            ->assertJsonCount(2, 'schedules');

        $this->assertDatabaseHas('schedules', ['id' => $monday->id, 'faculty_id' => $fixture['faculty']->id]);
        $this->assertDatabaseHas('schedules', ['id' => $tuesday->id, 'faculty_id' => $fixture['faculty']->id]);
    }

    public function test_a_conflicting_assignment_rolls_back_the_whole_set(): void
    {
        $fixture = $this->fixture();

        // Already taught by the instructor at Monday 08:00.
        $this->schedule($fixture, [
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'faculty_id' => $fixture['faculty']->id,
        ]);

        $clean = $this->schedule($fixture, ['day' => 'Wednesday', 'start_time' => '13:00', 'end_time' => '14:00']);
        $clashing = $this->schedule($fixture, [
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'section_id' => $fixture['otherSection']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);

        $response = $this->actingAs($fixture['user'])->patchJson('/api/schedules/batch-faculty', [
            'assignments' => [[
                'schedule_ids' => [$clean->id, $clashing->id],
                'faculty_id' => $fixture['faculty']->id,
            ]],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'violations'])
            // The report names the offending row so the UI can point at it.
            ->assertJsonPath('violations.0.schedule_id', $clashing->id);

        // The clean assignment that was applied first must not survive.
        $this->assertDatabaseHas('schedules', ['id' => $clean->id, 'faculty_id' => null]);
        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);
    }

    public function test_two_schedules_at_the_same_hour_cannot_share_an_instructor(): void
    {
        $fixture = $this->fixture();

        // Neither row conflicts on its own; together they double-book the
        // instructor. Writing each row before validating the next is what lets
        // the RuleEngine see the in-flight assignment and catch this.
        $first = $this->schedule($fixture, ['day' => 'Thursday', 'start_time' => '10:00', 'end_time' => '11:00']);
        $second = $this->schedule($fixture, [
            'day' => 'Thursday',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'section_id' => $fixture['otherSection']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);

        $response = $this->actingAs($fixture['user'])->patchJson('/api/schedules/batch-faculty', [
            'assignments' => [[
                'schedule_ids' => [$first->id, $second->id],
                'faculty_id' => $fixture['faculty']->id,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('schedules', ['id' => $first->id, 'faculty_id' => null]);
        $this->assertDatabaseHas('schedules', ['id' => $second->id, 'faculty_id' => null]);
    }

    public function test_a_schedule_may_not_appear_in_two_assignments(): void
    {
        $fixture = $this->fixture();
        $target = $this->schedule($fixture, ['day' => 'Friday', 'start_time' => '15:00', 'end_time' => '16:00']);
        $other = Faculty::create([
            'first_name' => 'Second',
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'department_id' => $fixture['department']->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($fixture['user'])->patchJson('/api/schedules/batch-faculty', [
            'assignments' => [
                ['schedule_ids' => [$target->id], 'faculty_id' => $fixture['faculty']->id],
                ['schedule_ids' => [$target->id], 'faculty_id' => $other->id],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('schedules', ['id' => $target->id, 'faculty_id' => null]);
    }

    public function test_schedules_from_another_department_are_refused(): void
    {
        $fixture = $this->fixture();
        $target = $this->schedule($fixture, ['day' => 'Friday', 'start_time' => '15:00', 'end_time' => '16:00']);
        $outsider = User::factory()->create([
            'role' => 'secretary',
            'department_id' => Departments::create(['department_name' => 'Other', 'department_code' => 'OTH'])->id,
        ]);

        $this->actingAs($outsider)
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [[
                    'schedule_ids' => [$target->id],
                    'faculty_id' => $fixture['faculty']->id,
                ]],
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('schedules', ['id' => $target->id, 'faculty_id' => null]);
    }

    public function test_a_null_faculty_id_clears_the_assignment(): void
    {
        $fixture = $this->fixture();
        $target = $this->schedule($fixture, [
            'day' => 'Friday',
            'start_time' => '15:00',
            'end_time' => '16:00',
            'faculty_id' => $fixture['faculty']->id,
        ]);

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => [$target->id], 'faculty_id' => null]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedules', ['id' => $target->id, 'faculty_id' => null]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Bulk Dept', 'department_code' => 'BLK']);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'department' => $department,
            'term' => $term,
            'room' => Rooms::create(['room_code' => 'BLK101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'otherRoom' => Rooms::create(['room_code' => 'BLK102', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create([
                'course_code' => 'BLK101',
                'course_name' => 'Bulk Course',
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
                'section_name' => 'BLK-1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]),
            'otherSection' => Sections::create([
                'section_name' => 'BLK-1B',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'Bulk',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
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
            // Instructor assignment is only legal once VPAA approval has moved the
            // row into the assignment stage; these tests are about atomicity, so
            // they start where assignment is allowed.
            'status' => 'faculty_assignment',
        ], $overrides));
    }
}
