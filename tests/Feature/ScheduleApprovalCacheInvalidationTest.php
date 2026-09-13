<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every approval screen is built from `/initial-data`, whose encoded payload is
 * cached for five minutes. A workflow transition that does not invalidate that
 * cache reports success and then serves the pre-transition payload back to the
 * very screen that triggered it -- an approved schedule sitting in the Pending
 * queue until the entry happens to expire.
 *
 * Each test below warms the cache, performs one transition, and re-reads the
 * payload in the same request cycle the UI would.
 */
class ScheduleApprovalCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dean_approval_is_visible_in_the_next_initial_data_read(): void
    {
        ['department' => $department, 'section' => $section, 'schedule' => $schedule] = $this->fixture('submitted');
        $submission = $this->submission($department, $section, 'pending_dean');
        $dean = $this->grantCapabilities(
            User::factory()->create(['role' => 'dean', 'department_id' => $department->id]),
            ['schedule.view', 'schedule.approve_dean'],
        );

        // Warms the cached payload the queue is drawn from.
        $this->actingAs($dean)->getJson('/api/initial-data')->assertOk()
            ->assertJsonPath('schedule_submissions.0.status', 'pending_dean');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean")
            ->assertOk();

        $this->actingAs($dean)->getJson('/api/initial-data')->assertOk()
            ->assertJsonPath('schedule_submissions.0.status', 'pending_vpaa')
            ->assertJsonPath('schedules.0.status', 'approved_by_dean');

        $this->assertSame('pending_vpaa', $submission->refresh()->status);
        $this->assertSame('approved_by_dean', $schedule->refresh()->status);
    }

    public function test_vpaa_approval_is_visible_in_the_next_initial_data_read(): void
    {
        ['department' => $department, 'section' => $section] = $this->fixture('approved_by_dean');
        $this->submission($department, $section, 'pending_vpaa');
        $vpaa = $this->grantCapabilities(
            User::factory()->create(['role' => 'vpaa', 'department_id' => null]),
            ['schedule.view', 'schedule.approve_vpaa'],
        );

        $this->actingAs($vpaa)->getJson('/api/initial-data')->assertOk()
            ->assertJsonPath('schedule_submissions.0.status', 'pending_vpaa');

        $this->actingAs($vpaa)
            ->postJson("/api/departments/{$department->id}/approve-by-vpaa")
            ->assertOk();

        $this->actingAs($vpaa)->getJson('/api/initial-data')->assertOk()
            ->assertJsonPath('schedule_submissions.0.status', 'approved')
            ->assertJsonPath('schedules.0.status', 'faculty_assignment');
    }

    public function test_a_dean_return_is_visible_in_the_next_initial_data_read(): void
    {
        ['department' => $department, 'section' => $section] = $this->fixture('submitted');
        $this->submission($department, $section, 'pending_dean');
        $dean = $this->grantCapabilities(
            User::factory()->create(['role' => 'dean', 'department_id' => $department->id]),
            ['schedule.view', 'schedule.approve_dean'],
        );

        $this->actingAs($dean)->getJson('/api/initial-data')->assertOk()
            ->assertJsonPath('schedule_submissions.0.status', 'pending_dean');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/return-by-dean", [
                'rejection_reason' => 'Two classes overlap on Monday.',
            ])
            ->assertOk();

        $this->actingAs($dean)->getJson('/api/initial-data')->assertOk()
            ->assertJsonPath('schedule_submissions.0.status', 'rejected_by_dean');
    }

    /** @return array{department: Departments, section: Sections, schedule: Schedule} */
    private function fixture(string $scheduleStatus): array
    {
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $room = Rooms::create([
            'room_code' => 'CIT 101',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Programming',
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
            'section_name' => 'BSIT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $schedule = Schedule::create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => $scheduleStatus,
        ]);

        return compact('department', 'section', 'schedule');
    }

    private function submission(Departments $department, Sections $section, string $status): ScheduleSubmission
    {
        $submission = ScheduleSubmission::create([
            'department_id' => $department->id,
            'term_id' => $section->term_id,
            'revision_number' => 1,
            'status' => $status,
            'submitted_at' => now(),
        ]);
        $submission->sections()->attach([$section->id], ['state' => 'included']);

        return $submission;
    }
}
