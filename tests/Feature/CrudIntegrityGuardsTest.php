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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Edits and deletions that used to route around a rule enforced elsewhere:
 * a section taking its submitted classes with it, a course leaving a curriculum
 * its cohorts are still timetabled on, and a restore bringing back a record
 * under an archived parent or into an occupied role slot.
 */
class CrudIntegrityGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_section_with_submitted_classes_cannot_be_deleted(): void
    {
        $fixture = $this->fixture();
        $section = $this->section($fixture);
        $schedule = $this->schedule($fixture, $section, 'submitted');

        $this->actingAs($fixture['secretary'])
            ->deleteJson("/api/sections/{$section->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('sections', ['id' => $section->id]);
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id]);
    }

    public function test_a_section_with_only_draft_classes_can_still_be_deleted(): void
    {
        $fixture = $this->fixture();
        $section = $this->section($fixture);
        $this->schedule($fixture, $section, 'draft');

        $this->actingAs($fixture['secretary'])
            ->deleteJson("/api/sections/{$section->id}")
            ->assertOk();

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }

    public function test_a_locked_section_refuses_structural_edits_but_accepts_a_rename(): void
    {
        $fixture = $this->fixture();
        $section = $this->section($fixture);
        $this->schedule($fixture, $section, 'approved_by_dean');

        $this->actingAs($fixture['secretary'])
            ->putJson("/api/sections/{$section->id}", $this->editPayload($fixture, 'BSIT 1A', '2'))
            ->assertStatus(422);
        $this->assertSame('1', (string) $section->fresh()->year_level);

        // The edit form resends every field; unchanged ones must not trip the lock.
        $this->actingAs($fixture['secretary'])
            ->putJson("/api/sections/{$section->id}", $this->editPayload($fixture, 'BSIT 1Z', '1'))
            ->assertOk()
            ->assertJsonPath('section_name', 'BSIT 1Z');
    }

    public function test_the_semester_label_follows_the_semester_row(): void
    {
        $fixture = $this->fixture();
        $section = $this->section($fixture);

        $this->actingAs($fixture['secretary'])
            ->patchJson("/api/sections/{$section->id}", ['semester' => '2nd'])
            ->assertOk();

        $this->assertSame('1st', $section->fresh()->semester);
    }

    public function test_a_course_scheduled_for_a_following_section_cannot_leave_the_curriculum(): void
    {
        $fixture = $this->fixture();
        $curriculum = Curriculum::create([
            'name' => 'BSIT 2026', 'code' => 'BSIT-2026',
            'department_id' => $fixture['department']->id,
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $scheduled = $this->course($fixture, 'IT 101');
        $unscheduled = $this->course($fixture, 'IT 102');
        foreach ([$scheduled, $unscheduled] as $course) {
            DB::table('curriculum_course')->insert([
                'curriculum_id' => $curriculum->id, 'course_id' => $course->id,
                'year_level' => 1, 'semester' => 1,
            ]);
        }
        $section = $this->section($fixture, ['curriculum_id' => $curriculum->id]);
        $this->schedule($fixture, $section, 'draft', $scheduled);

        $this->actingAs($fixture['secretary'])
            ->deleteJson("/api/curriculum/{$curriculum->id}/courses/{$scheduled->id}")
            ->assertStatus(422)
            ->assertJsonPath('blocking_sections.0.id', $section->id);
        $this->assertDatabaseHas('curriculum_course', ['curriculum_id' => $curriculum->id, 'course_id' => $scheduled->id]);

        $this->actingAs($fixture['secretary'])
            ->deleteJson("/api/curriculum/{$curriculum->id}/courses/{$unscheduled->id}")
            ->assertOk();
    }

    public function test_a_room_with_classes_this_semester_cannot_be_marked_unavailable(): void
    {
        $fixture = $this->fixture();
        $room = Rooms::create(['room_code' => 'CIT-101', 'room_type' => 'lecture', 'department_id' => $fixture['department']->id]);
        $schedule = $this->schedule($fixture, $this->section($fixture), 'draft');
        $schedule->update(['room_id' => $room->id]);

        $this->actingAs($fixture['vpaa'], 'sanctum')
            ->putJson("/api/rooms/{$room->id}", ['status' => 'not available'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'available']);

        // A past semester's classes do not hold the room open.
        $fixture['semester']->update(['is_active' => false]);
        $this->actingAs($fixture['vpaa'], 'sanctum')
            ->putJson("/api/rooms/{$room->id}", ['status' => 'not available'])
            ->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => 'not available']);
    }

    public function test_a_record_under_an_archived_department_cannot_be_restored(): void
    {
        $fixture = $this->fixture();
        $department = Departments::create(['department_name' => 'Closed College', 'department_code' => 'CLC']);
        $room = Rooms::create(['room_code' => 'CLC-101', 'room_type' => 'lecture', 'department_id' => $department->id]);
        $room->delete();
        $department->delete();

        $this->actingAs($fixture['vpaa'], 'sanctum')
            ->postJson("/api/archives/rooms/{$room->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Restore the Closed College department from Archives first.');
        $this->assertSoftDeleted('rooms', ['id' => $room->id]);

        $this->actingAs($fixture['vpaa'], 'sanctum')
            ->postJson("/api/archives/departments/{$department->id}/restore")
            ->assertOk();
        $this->actingAs($fixture['vpaa'], 'sanctum')
            ->postJson("/api/archives/rooms/{$room->id}/restore")
            ->assertOk();
    }

    public function test_a_restored_account_whose_slot_was_refilled_comes_back_inactive(): void
    {
        $fixture = $this->fixture();
        $archived = User::factory()->create([
            'role' => 'dean', 'department_id' => $fixture['department']->id, 'is_active' => true,
        ]);
        $archived->delete();
        User::factory()->create([
            'role' => 'dean', 'department_id' => $fixture['department']->id, 'is_active' => true,
        ]);

        $this->actingAs($fixture['vpaa'], 'sanctum')
            ->postJson("/api/archives/users/{$archived->id}/restore")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $archived->id, 'deleted_at' => null, 'is_active' => false]);
        $this->assertSame(1, User::where('role', 'dean')->where('department_id', $fixture['department']->id)->where('is_active', true)->count());
    }

    /** @return array<string, mixed> */
    private function editPayload(array $fixture, string $name, string $yearLevel): array
    {
        return [
            'section_name' => $name,
            'year_level' => $yearLevel,
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
        ];
    }

    private function section(array $fixture, array $overrides = []): Sections
    {
        return Sections::create([
            'section_name' => 'BSIT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'semester_id' => $fixture['semester']->id,
            'status' => 'active',
            ...$overrides,
        ]);
    }

    private function course(array $fixture, string $code): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => $code,
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $fixture['department']->id, 'status' => 'active',
        ]);
    }

    private function schedule(array $fixture, Sections $section, string $status, ?Course $course = null): Schedule
    {
        return Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $section->id,
            'curriculum_id' => $section->curriculum_id,
            'course_id' => ($course ?? $this->course($fixture, 'GEN 100'))->id,
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'day' => 'Monday',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'mode' => 'on-site',
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'program' => $program,
            'secretary' => $this->grantCapabilities(
                User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
            ),
            'vpaa' => User::factory()->create(['role' => 'vpaa']),
        ];
    }
}
