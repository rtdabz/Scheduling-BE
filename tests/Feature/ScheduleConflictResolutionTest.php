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
 * Resolution is a transaction, not a button.
 *
 * The conflict inbox derives its entries from the persisted timetable, and a
 * resolution is accepted only when a fresh scan inside the same transaction no
 * longer produces the conflict's id. Anything that fails -- a move that leaves
 * the clash, a move that creates a new one, a class locked at an approval
 * stage -- rolls back whole, and nothing is ever written to `schedules.status`
 * to mark a conflict resolved.
 */
class ScheduleConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_inbox_lists_a_section_conflict_with_its_resolution_options(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);

        $response = $this->actingAs($fixture['user'])
            ->getJson('/api/conflicts?semester_id='.$fixture['semester']->id)
            ->assertOk();

        $conflicts = collect($response->json('conflicts'))->keyBy('rule');
        $this->assertTrue($conflicts->has('section_conflict'), 'The two classes share a section and a time.');
        $this->assertSame(
            "section_conflict:{$left->id}:{$right->id}",
            $conflicts['section_conflict']['id'],
            'A conflict id is its rule and its pair, low id first.',
        );
        $this->assertContains('move_schedule', $conflicts['section_conflict']['resolution_options']);
    }

    public function test_an_archived_class_is_not_scanned_as_a_conflict(): void
    {
        $fixture = $this->fixture();
        $live = $this->schedule($fixture);
        $archived = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);
        $archived->delete();

        $response = $this->actingAs($fixture['user'])
            ->getJson('/api/conflicts?semester_id='.$fixture['semester']->id)
            ->assertOk();

        $this->assertSame(
            [],
            $response->json('conflicts'),
            'A deleted meeting is off the timetable, so it cannot clash with one that is still on it.',
        );
        $this->assertNotNull($live->fresh(), 'Only the archived row was removed.');
    }

    public function test_moving_a_class_resolves_the_conflict_and_records_history_and_an_audit_entry(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);
        $conflictId = "section_conflict:{$left->id}:{$right->id}";

        $response = $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Monday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'reason' => 'Moved to a free slot.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'resolved')
            ->assertJsonPath('conflict_id', $conflictId);

        $this->assertSame([], $response->json('remaining_conflicts'));
        $this->assertDatabaseHas('schedules', [
            'id' => $right->id,
            'start_time' => '10:00',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('scheduling_audit_logs', ['action' => 'conflict_resolved']);
        $this->assertDatabaseHas('schedule_history_versions', [
            'action' => 'conflict_resolved',
            'reason' => 'Moved to a free slot.',
        ]);
        $this->assertDatabaseHas('schedule_history_items', ['original_schedule_id' => $right->id]);
    }

    public function test_a_move_that_leaves_the_conflict_in_place_is_rejected_whole(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);
        $conflictId = "section_conflict:{$left->id}:{$right->id}";

        // Still inside the window it clashes over, so the section conflict stands.
        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Monday',
                'start_time' => '08:00',
                'end_time' => '09:00',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('schedules', ['id' => $right->id, 'start_time' => '08:00']);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'conflict_resolved']);
        $this->assertDatabaseMissing('schedule_history_versions', ['action' => 'conflict_resolved']);
    }

    public function test_a_move_into_another_clash_is_refused_and_rolled_back(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);
        // A third class already holds 10:00 in the same section.
        $this->schedule($fixture, [
            'course_id' => $fixture['thirdCourse']->id,
            'room_id' => $fixture['thirdRoom']->id,
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);
        $conflictId = "section_conflict:{$left->id}:{$right->id}";

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Monday',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('schedules', ['id' => $right->id, 'start_time' => '08:00']);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'conflict_resolved']);
    }

    public function test_a_room_conflict_is_resolved_by_moving_the_class_to_another_room(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'section_id' => $fixture['otherSection']->id,
            'course_id' => $fixture['otherCourse']->id,
        ]);
        $conflictId = "room_conflict:{$left->id}:{$right->id}";

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/resolve", [
                'action' => 'change_room',
                'schedule_id' => $right->id,
                'room_id' => $fixture['otherRoom']->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');

        $this->assertDatabaseHas('schedules', ['id' => $right->id, 'room_id' => $fixture['otherRoom']->id]);
    }

    public function test_a_faculty_conflict_is_resolved_by_reassigning_the_instructor(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture, ['faculty_id' => $fixture['faculty']->id]);
        $right = $this->schedule($fixture, [
            'section_id' => $fixture['otherSection']->id,
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
            'faculty_id' => $fixture['faculty']->id,
        ]);
        $conflictId = "faculty_conflict:{$left->id}:{$right->id}";

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/resolve", [
                'action' => 'reassign_instructor',
                'schedule_id' => $right->id,
                'faculty_id' => $fixture['otherFaculty']->id,
                'confirm_overload' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');

        $this->assertDatabaseHas('schedules', [
            'id' => $right->id,
            'faculty_id' => $fixture['otherFaculty']->id,
        ]);
    }

    public function test_an_override_needs_a_reason_and_leaves_both_meetings_marked(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture, ['faculty_id' => $fixture['faculty']->id]);
        $right = $this->schedule($fixture, [
            'section_id' => $fixture['otherSection']->id,
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
            'faculty_id' => $fixture['faculty']->id,
        ]);
        $conflictId = "faculty_conflict:{$left->id}:{$right->id}";

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/override", ['confirm' => true])
            ->assertStatus(422);

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/override", [
                'confirm' => true,
                'reason' => 'Department head approved the double booking.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'overridden');

        $this->assertDatabaseHas('schedules', ['id' => $left->id, 'faculty_conflict_override' => true]);
        $this->assertDatabaseHas('schedules', ['id' => $right->id, 'faculty_conflict_override' => true]);
        $this->assertDatabaseHas('scheduling_audit_logs', ['action' => 'conflict_overridden']);
    }

    public function test_a_section_conflict_cannot_be_overridden(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/section_conflict:{$left->id}:{$right->id}/override", [
                'confirm' => true,
                'reason' => 'We would like this to stand.',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'conflict_overridden']);
    }

    public function test_a_conflict_that_is_already_gone_answers_409_with_the_open_list(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/section_conflict:{$left->id}:{$right->id}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Tuesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'conflicts']);
    }

    public function test_a_class_named_in_the_request_must_belong_to_the_conflict(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);
        $unrelated = $this->schedule($fixture, [
            'course_id' => $fixture['thirdCourse']->id,
            'room_id' => $fixture['thirdRoom']->id,
            'day' => 'Friday',
        ]);

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/section_conflict:{$left->id}:{$right->id}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $unrelated->id,
                'day' => 'Friday',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('schedules', ['id' => $unrelated->id, 'start_time' => '08:00']);
    }

    public function test_a_class_locked_at_an_approval_stage_cannot_be_replotted_here(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
            'status' => 'approved',
        ]);

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/section_conflict:{$left->id}:{$right->id}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Monday',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('schedules', ['id' => $right->id, 'start_time' => '08:00']);
    }

    public function test_another_departments_class_cannot_be_resolved(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);

        $elsewhere = Departments::create(['department_name' => 'Elsewhere', 'department_code' => 'ELS']);
        Program::create(['department_id' => $elsewhere->id, 'code' => 'ELSP', 'name' => 'Elsewhere Program']);
        $outsider = $this->grantCapabilities(
            User::factory()->create(['role' => 'secretary', 'department_id' => $elsewhere->id]),
        );

        $this->actingAs($outsider)
            ->postJson("/api/conflicts/section_conflict:{$left->id}:{$right->id}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Monday',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('schedules', ['id' => $right->id, 'start_time' => '08:00']);
    }

    public function test_resolving_without_the_update_capability_is_refused(): void
    {
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);

        $reader = $this->grantCapabilities(
            // A Dean holds schedule.view but not schedule.update, so the only
            // capability needed here is reading the inbox.
            User::factory()->create(['role' => 'dean', 'department_id' => $fixture['department']->id]),
            ['schedule.view'],
        );

        $this->actingAs($reader)
            ->postJson("/api/conflicts/section_conflict:{$left->id}:{$right->id}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Monday',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(403);
    }

    public function test_a_percent_encoded_conflict_id_reaches_the_same_conflict(): void
    {
        // The client encodes the id into the path, so the colons arrive as %3A.
        $fixture = $this->fixture();
        $left = $this->schedule($fixture);
        $right = $this->schedule($fixture, [
            'course_id' => $fixture['otherCourse']->id,
            'room_id' => $fixture['otherRoom']->id,
        ]);
        $conflictId = rawurlencode("section_conflict:{$left->id}:{$right->id}");

        $this->actingAs($fixture['user'])
            ->postJson("/api/conflicts/{$conflictId}/resolve", [
                'action' => 'move_schedule',
                'schedule_id' => $right->id,
                'day' => 'Monday',
                'start_time' => '10:00',
                'end_time' => '11:00',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');
    }

    public function test_an_unknown_conflict_identifier_is_a_404(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])
            ->postJson('/api/conflicts/not-a-conflict/override', [
                'confirm' => true,
                'reason' => 'Nothing to allow.',
            ])
            ->assertStatus(404);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Conflict Dept', 'department_code' => 'CFL']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'CFLP', 'name' => 'Conflict Program']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $course = fn (string $code): Course => Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
            'lecture_hours' => 1,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        $room = fn (string $code): Rooms => Rooms::create([
            'room_code' => $code,
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $section = fn (string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $faculty = fn (string $first): Faculty => Faculty::create([
            'first_name' => $first,
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        return [
            'department' => $department,
            'program' => $program,
            'semester' => $semester,
            'course' => $course('CFL101'),
            'otherCourse' => $course('CFL102'),
            'thirdCourse' => $course('CFL103'),
            'room' => $room('CFL201'),
            'otherRoom' => $room('CFL202'),
            'thirdRoom' => $room('CFL203'),
            'section' => $section('CFL-1A'),
            'otherSection' => $section('CFL-1B'),
            'faculty' => $faculty('First'),
            'otherFaculty' => $faculty('Second'),
            'user' => $this->grantCapabilities(
                User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
            ),
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
            'status' => 'draft',
        ], $overrides));
    }
}
