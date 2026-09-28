<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A department can hold several submissions awaiting the same reviewer --
 * one per program, or a revision beside a partially recalled cohort. The
 * approval endpoints are keyed by department, so each review has to name the
 * submission it acts on; otherwise the reviewer approves or returns whichever
 * submission happens to be newest.
 */
class ScheduleApprovalTargetingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dean_return_acts_only_on_the_named_submission(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $first = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'submitted']);
        $second = $this->schedule($department, $semester, $room, $course, $secondSection, ['status' => 'submitted']);
        $older = $this->submission($department, $semester, [$firstSection], 'pending_dean');
        $newer = $this->submission($department, $semester, [$secondSection], 'pending_dean');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/return-by-dean", [
                'schedule_submission_id' => $older->id,
                'rejection_reason' => 'Fix the first program.',
            ])
            ->assertOk()
            ->assertJsonPath('schedule_submission_id', $older->id);

        $this->assertSame('rejected_by_dean', $first->refresh()->status);
        $this->assertSame('submitted', $second->refresh()->status);
        $this->assertSame('rejected_by_dean', $older->refresh()->status);
        $this->assertSame('pending_dean', $newer->refresh()->status);
    }

    public function test_dean_and_vpaa_approve_only_the_named_submission(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $first = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'submitted']);
        $second = $this->schedule($department, $semester, $room, $course, $secondSection, ['status' => 'submitted']);
        $older = $this->submission($department, $semester, [$firstSection], 'pending_dean');
        $this->submission($department, $semester, [$secondSection], 'pending_dean');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean", ['schedule_submission_id' => $older->id])
            ->assertOk();
        $this->assertSame('approved_by_dean', $first->refresh()->status);
        $this->assertSame('submitted', $second->refresh()->status);

        $this->actingAs($vpaa)
            ->postJson("/api/departments/{$department->id}/approve-by-vpaa", ['schedule_submission_id' => $older->id])
            ->assertOk();
        $this->assertSame('faculty_assignment', $first->refresh()->status);
        $this->assertSame('approved', $older->refresh()->status);
        $this->assertSame('submitted', $second->refresh()->status);
    }

    public function test_the_sections_left_in_a_partially_recalled_submission_can_be_reviewed(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $recalled = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'revision']);
        $remaining = $this->schedule($department, $semester, $room, $course, $secondSection, ['status' => 'submitted']);
        $submission = $this->submission($department, $semester, [$firstSection, $secondSection], 'partially_withdrawn');
        $submission->sections()->updateExistingPivot($firstSection->id, ['state' => 'withdrawn']);

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean", ['schedule_submission_id' => $submission->id])
            ->assertOk()
            ->assertJsonPath('schedules_updated', 1);
        $this->assertSame('approved_by_dean', $remaining->refresh()->status);
        $this->assertSame('revision', $recalled->refresh()->status);
        // The recall stays on record; the stage of what remains is read from its meetings.
        $this->assertSame('partially_withdrawn', $submission->refresh()->status);
        $this->assertSame($dean->id, $submission->dean_reviewed_by);

        $this->actingAs($vpaa)
            ->postJson("/api/departments/{$department->id}/approve-by-vpaa", ['schedule_submission_id' => $submission->id])
            ->assertOk();
        $this->assertSame('faculty_assignment', $remaining->refresh()->status);
        $this->assertSame('revision', $recalled->refresh()->status);
        $this->assertSame(1, ScheduleSubmission::query()->count());
    }

    public function test_a_submission_already_past_the_stage_cannot_be_reviewed_again(): void
    {
        [$department, $semester, $room, $course, $firstSection] = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'approved_by_dean']);
        $submission = $this->submission($department, $semester, [$firstSection], 'pending_vpaa');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/return-by-dean", [
                'schedule_submission_id' => $submission->id,
                'rejection_reason' => 'Too late.',
            ])
            ->assertStatus(422);

        $this->assertSame('approved_by_dean', $schedule->refresh()->status);
        $this->assertSame('pending_vpaa', $submission->refresh()->status);
    }

    public function test_a_submission_of_another_department_cannot_be_named(): void
    {
        [$department, $semester, $room, $course, $firstSection] = $this->fixture();
        $other = Departments::create(['department_name' => 'Nursing', 'department_code' => 'CON']);
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'submitted']);
        $foreign = ScheduleSubmission::create([
            'department_id' => $other->id,
            'semester_id' => $semester->id,
            'revision_number' => 1,
            'status' => 'pending_dean',
            'submitted_at' => now(),
        ]);

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean", ['schedule_submission_id' => $foreign->id])
            ->assertStatus(422);
        $this->assertSame('pending_dean', $foreign->refresh()->status);
    }

    public function test_room_tba_cannot_be_approved_without_the_override(): void
    {
        [$department, $semester, $room, $course, $firstSection] = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'submitted',
            'room_id' => null,
        ]);
        $submission = $this->submission($department, $semester, [$firstSection], 'pending_dean');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean", ['schedule_submission_id' => $submission->id])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'room_tba_override_required');
        $this->assertSame('submitted', $schedule->refresh()->status);

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean", [
                'schedule_submission_id' => $submission->id,
                'override_room_tba' => true,
                'override_reason' => 'Lab room assigned next week.',
            ])
            ->assertOk();
        $this->assertSame('conditionally_approved', $schedule->refresh()->status);
        $this->assertTrue((bool) $submission->refresh()->approval_override);
    }

    public function test_the_override_is_not_recorded_when_no_room_is_tba(): void
    {
        [$department, $semester, $room, $course, $firstSection] = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'submitted']);
        $submission = $this->submission($department, $semester, [$firstSection], 'pending_dean');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean", [
                'schedule_submission_id' => $submission->id,
                'override_room_tba' => true,
                'override_reason' => 'Stale page.',
            ])
            ->assertOk();

        $this->assertSame('approved_by_dean', $schedule->refresh()->status);
        $this->assertFalse((bool) $submission->refresh()->approval_override);
        $this->assertNull($submission->approval_override_reason);
    }

    private function fixture(): array
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
        $semester = Semester::create([
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
        $sections = collect(['BSIT 1A', 'BSIT 1B'])->map(fn (string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]));

        return [$department, $semester, $room, $course, $sections[0], $sections[1]];
    }

    private function schedule(Departments $department, Semester $semester, Rooms $room, Course $course, Sections $section, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'completed',
        ], $overrides));
    }

    private function submission(Departments $department, Semester $semester, array $sections, string $status): ScheduleSubmission
    {
        $submission = ScheduleSubmission::create([
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'revision_number' => ((int) ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('semester_id', $semester->id)
                ->max('revision_number')) + 1,
            'status' => $status,
            'submitted_at' => now(),
        ]);
        $submission->sections()->attach(
            collect($sections)->map(static fn (Sections $section): int => $section->id)->all(),
            ['state' => 'included'],
        );

        return $submission->load('sections');
    }
}
