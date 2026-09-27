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
 * Status and delete paths outside the approval endpoints must not move rows
 * into, out of or through an approval stage, nor remove them from one.
 */
class ScheduleWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_status_cannot_approve_a_draft_directly(): void
    {
        $f = $this->fixture();
        $schedule = $this->schedule($f, $f['courseA'], ['status' => 'draft']);

        $this->actingAs($f['user'])->patchJson('/api/schedules/batch-status', [
            'ids' => [$schedule->id],
            'status' => 'approved',
        ])->assertStatus(422)->assertJsonPath('blocked_schedule_ids', [$schedule->id]);

        $this->assertSame('draft', $schedule->refresh()->status);
    }

    public function test_batch_status_cannot_pull_submitted_rows_back_to_draft(): void
    {
        $f = $this->fixture();
        $submitted = $this->schedule($f, $f['courseA'], ['status' => 'submitted']);
        $completed = $this->schedule($f, $f['courseB'], ['status' => 'completed']);

        // A stale Edit used to send every row of the section.
        $this->actingAs($f['user'])->patchJson('/api/schedules/batch-status', [
            'ids' => [$submitted->id, $completed->id],
            'status' => 'draft',
        ])->assertStatus(422);

        $this->assertSame('submitted', $submitted->refresh()->status);
        $this->assertSame('completed', $completed->refresh()->status);
    }

    public function test_batch_status_allows_the_manual_steps(): void
    {
        $f = $this->fixture();
        $draft = $this->schedule($f, $f['courseA'], ['status' => 'draft']);
        $returned = $this->schedule($f, $f['courseB'], ['status' => 'rejected_by_dean']);

        $this->actingAs($f['user'])->patchJson('/api/schedules/batch-status', [
            'ids' => [$draft->id],
            'status' => 'completed',
        ])->assertOk();
        $this->actingAs($f['user'])->patchJson('/api/schedules/batch-status', [
            'ids' => [$draft->id],
            'status' => 'draft',
        ])->assertOk();
        $this->actingAs($f['user'])->patchJson('/api/schedules/batch-status', [
            'ids' => [$returned->id],
            'status' => 'revision',
        ])->assertOk();

        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertSame('revision', $returned->refresh()->status);
    }

    public function test_batch_status_only_operation_cannot_submit_a_row(): void
    {
        $f = $this->fixture();
        $schedule = $this->schedule($f, $f['courseA'], ['status' => 'draft']);

        $this->actingAs($f['user'])->postJson('/api/schedules/batch', [
            'operations' => [['id' => $schedule->id, 'status' => 'submitted']],
        ])->assertStatus(422);

        $this->assertSame('draft', $schedule->refresh()->status);
    }

    public function test_locked_rows_cannot_be_deleted(): void
    {
        $f = $this->fixture();
        $approved = $this->schedule($f, $f['courseA'], ['status' => 'faculty_assignment']);
        $submitted = $this->schedule($f, $f['courseB'], ['status' => 'submitted']);

        $this->actingAs($f['user'])->deleteJson("/api/schedules/{$approved->id}")->assertStatus(422);
        $this->actingAs($f['user'])->postJson('/api/schedules/batch', [
            'operations' => [],
            'delete_ids' => [$submitted->id],
        ])->assertStatus(422);

        $this->assertNotSoftDeleted('schedules', ['id' => $approved->id]);
        $this->assertNotSoftDeleted('schedules', ['id' => $submitted->id]);
    }

    public function test_returned_rows_can_still_be_deleted(): void
    {
        $f = $this->fixture();
        $returned = $this->schedule($f, $f['courseA'], ['status' => 'rejected']);

        $this->actingAs($f['user'])->deleteJson("/api/schedules/{$returned->id}")->assertOk();

        $this->assertSoftDeleted('schedules', ['id' => $returned->id]);
    }

    public function test_applying_a_generated_timetable_keeps_courses_left_out_of_generation(): void
    {
        $f = $this->fixture();
        $regenerated = $this->schedule($f, $f['courseA'], ['status' => 'revision']);
        $excluded = $this->schedule($f, $f['courseB'], ['status' => 'revision', 'day' => 'Tuesday']);

        $this->actingAs($f['user'])->postJson('/api/schedules/batch', [
            'operations' => [[
                'semester_id' => $f['semester']->id,
                'section_id' => $f['section']->id,
                'course_id' => $f['courseA']->id,
                'room_id' => $f['room']->id,
                'department_id' => $f['department']->id,
                'day' => 'Thursday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]],
            'replace_section_ids' => [$f['section']->id],
            'replace_semester_id' => $f['semester']->id,
        ])->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $regenerated->id]);
        $this->assertDatabaseHas('schedules', ['id' => $excluded->id, 'status' => 'revision', 'deleted_at' => null]);
    }

    public function test_reset_still_clears_the_whole_section(): void
    {
        $f = $this->fixture();
        $first = $this->schedule($f, $f['courseA'], ['status' => 'draft']);
        $second = $this->schedule($f, $f['courseB'], ['status' => 'revision', 'day' => 'Tuesday']);

        $this->actingAs($f['user'])->postJson('/api/schedules/batch', [
            'operations' => [],
            'delete_ids' => [$first->id],
            'replace_section_ids' => [$f['section']->id],
            'replace_semester_id' => $f['semester']->id,
        ])->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $first->id]);
        $this->assertDatabaseMissing('schedules', ['id' => $second->id]);
    }

    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Department A', 'department_code' => 'DEPA']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'PA', 'name' => 'Program A']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $room = Rooms::create(['room_code' => 'A101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $course = static fn (string $code): Course => Course::create([
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
        $section = Sections::create([
            'section_name' => 'A1',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [
            'department' => $department,
            'semester' => $semester,
            'room' => $room,
            'courseA' => $course('A101'),
            'courseB' => $course('A102'),
            'section' => $section,
            'user' => $user,
        ];
    }

    private function schedule(array $f, Course $course, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $f['semester']->id,
            'section_id' => $f['section']->id,
            'course_id' => $course->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['department']->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ], $overrides));
    }
}
