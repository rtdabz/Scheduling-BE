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
use App\Services\Scheduling\Schedule\ScheduleConflictCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An instructor can never be assigned over their own conflict: a double booking
 * or a part-timer outside their availability is refused on every assignment
 * route, and the old override flag is ignored. Manual selection remains available
 * after ranked instructor recommendations are retired.
 */
class InstructorConflictBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_assignment_refuses_a_clash_even_with_the_old_override_flag(): void
    {
        [$fixture, , $clashing] = $this->clash();

        foreach ([[], ['override_conflicts' => true]] as $extra) {
            $this->actingAs($fixture['user'])
                ->patchJson('/api/schedules/batch-faculty', array_merge([
                    'assignments' => [array_merge(
                        ['schedule_ids' => [$clashing->id], 'faculty_id' => $fixture['faculty']->id],
                        $extra,
                    )],
                ], $extra))
                ->assertStatus(422)
                ->assertJsonPath('violations.0.rule', 'faculty_conflict')
                ->assertJsonMissingPath('can_override_conflicts');
        }

        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);
    }

    public function test_the_single_class_picker_refuses_a_clash(): void
    {
        [$fixture, , $clashing] = $this->clash();

        $this->actingAs($fixture['user'])
            ->putJson("/api/schedules/{$clashing->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'override_conflicts' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'faculty_conflict');

        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);
    }

    public function test_the_instructor_assignment_page_refuses_a_clash(): void
    {
        [$fixture, , $clashing] = $this->clash();

        $this->actingAs($fixture['user'])
            ->patchJson("/api/instructor-assignments/{$clashing->id}", [
                'faculty_id' => $fixture['faculty']->id,
                'override_conflicts' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'faculty_conflict')
            ->assertJsonMissingPath('can_override_conflicts');

        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);
    }

    public function test_the_retired_endpoint_is_explicit_and_manual_selection_still_works(): void
    {
        [$fixture, , $clashing] = $this->clash();
        $free = Faculty::create([
            'first_name' => 'Free', 'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'department_id' => $fixture['department']->id, 'status' => 'active',
        ]);

        $this->actingAs($fixture['user'])
            ->getJson("/api/instructor-assignments/{$clashing->id}/recommendations")
            ->assertStatus(410)
            ->assertJsonPath('code', 'instructor_recommendations_retired')
            ->assertJsonPath('options', []);
        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);

        $this->patchJson("/api/instructor-assignments/{$clashing->id}", ['faculty_id' => $free->id])
            ->assertOk();
        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => $free->id]);
        $this->patchJson("/api/instructor-assignments/{$clashing->id}", ['faculty_id' => null])
            ->assertOk();
        $this->assertDatabaseHas('schedules', ['id' => $clashing->id, 'faculty_id' => null]);
    }

    public function test_the_retired_endpoint_keeps_capability_and_teaching_scope_guards(): void
    {
        [$fixture, , $clashing] = $this->clash();
        $url = "/api/instructor-assignments/{$clashing->id}/recommendations";
        $this->getJson($url)->assertUnauthorized();
        $noPermission = User::factory()->create(['role' => 'dean', 'department_id' => $fixture['department']->id]);
        $this->actingAs($noPermission)->getJson($url)->assertForbidden();
        $other = Departments::create(['department_name' => 'Outside', 'department_code' => 'OUT']);
        $outsider = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $other->id]));
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $head = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head', 'department_id' => $fixture['department']->id,
            'program_id' => $fixture['section']->program_id,
        ]));
        $this->actingAs($head)->getJson($url)->assertForbidden();
        $fixture['course']->update(['teaching_program_id' => $head->program_id]);
        $this->getJson($url)->assertStatus(410);
    }

    public function test_the_conflict_inbox_offers_no_way_to_let_a_clash_stand(): void
    {
        [$fixture, , $clashing] = $this->clash();
        Schedule::query()->whereKey($clashing->id)->update(['faculty_id' => $fixture['faculty']->id]);

        $query = "semester_id={$fixture['semester']->id}&department_id={$fixture['department']->id}";
        $conflict = $this->actingAs($fixture['user'])
            ->getJson("/api/conflicts?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'conflicts')
            ->assertJsonPath('conflicts.0.rule', 'faculty_conflict')
            ->assertJsonMissingPath('conflicts.0.allowed')
            ->json('conflicts.0');

        $this->assertSame(['reassign_instructor', 'move_schedule'], $conflict['resolution_options']);
        $this->postJson('/api/conflicts/'.rawurlencode($conflict['id']).'/override', [
            'reason' => 'Team taught.',
            'confirm' => true,
        ])->assertNotFound();

        $free = Faculty::create([
            'first_name' => 'Free',
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'department_id' => $fixture['department']->id,
            'status' => 'active',
        ]);
        $this->postJson('/api/conflicts/'.rawurlencode($conflict['id']).'/resolve', [
            'action' => 'reassign_instructor',
            'schedule_id' => $clashing->id,
            'faculty_id' => $free->id,
        ])->assertOk();

        $this->getJson("/api/conflicts?{$query}")->assertOk()->assertJsonCount(0, 'conflicts');
    }

    public function test_the_teaching_department_sees_and_fixes_a_clash_in_its_delegated_course(): void
    {
        $fixture = $this->fixture();
        $teaching = Departments::create(['department_name' => 'Teaching College', 'department_code' => 'TCH']);
        $fixture['course']->update(['teaching_department_id' => $teaching->id]);
        $busy = Faculty::create([
            'first_name' => 'Busy',
            'last_name' => 'Teacher',
            'employment_type' => 'full-time',
            'department_id' => $teaching->id,
            'status' => 'active',
        ]);
        $free = Faculty::create([
            'first_name' => 'Free',
            'last_name' => 'Teacher',
            'employment_type' => 'full-time',
            'department_id' => $teaching->id,
            'status' => 'active',
        ]);
        $this->schedule($fixture, ['faculty_id' => $busy->id]);
        $this->schedule($fixture, [
            'section_id' => $fixture['otherSection']->id,
            'room_id' => $fixture['otherRoom']->id,
            'faculty_id' => $busy->id,
        ]);
        $teacher = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $teaching->id]));

        $conflictId = $this->actingAs($teacher)
            ->getJson("/api/conflicts?semester_id={$fixture['semester']->id}&department_id={$teaching->id}")
            ->assertOk()
            ->assertJsonCount(1, 'conflicts')
            ->assertJsonPath('conflicts.0.rule', 'faculty_conflict')
            ->json('conflicts.0.id');

        $options = $this->getJson('/api/conflicts/'.rawurlencode($conflictId).'/recommendations')
            ->assertOk()
            ->json('options');

        $this->assertSame([], $options, "The teaching department may assign instructors but cannot move another department's classes.");
        $ids = ScheduleConflictCase::parseId($conflictId);
        $this->postJson('/api/conflicts/'.rawurlencode($conflictId).'/resolve', [
            'action' => 'reassign_instructor', 'schedule_id' => $ids['schedule_id'], 'faculty_id' => $free->id,
        ])->assertOk();
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
        $department = Departments::create(['department_name' => 'Clash Dept', 'department_code' => 'OVR']);
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
