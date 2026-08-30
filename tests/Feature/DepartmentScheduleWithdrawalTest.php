<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentScheduleWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_can_withdraw_selected_sections_after_vpaa_approval(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $first = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
        ]);
        $second = $this->schedule($department, $term, $room, $course, $secondSection, [
            'status' => 'faculty_assignment',
        ]);
        $submission = $this->submission($department, $term, [$firstSection, $secondSection], 'approved', [
            'dean_reviewed_by' => $vpaa->id,
            'dean_reviewed_at' => now(),
            'vpaa_reviewed_by' => $vpaa->id,
            'vpaa_reviewed_at' => now(),
        ]);

        $response = $this->actingAs($secretary)->postJson("/api/departments/{$department->id}/withdraw-submission", [
            'section_ids' => [$firstSection->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('withdrawal_stage', 'vpaa_approved')
            ->assertJsonPath('sections_unlocked', 1);

        $this->assertSame('revision', $first->refresh()->status);
        $this->assertSame('faculty_assignment', $second->refresh()->status);
        $this->assertSame('partially_withdrawn', $submission->refresh()->status);
        $this->assertSame($vpaa->id, $submission->dean_reviewed_by);
        $this->assertSame($vpaa->id, $submission->vpaa_reviewed_by);
        $this->assertDatabaseHas('schedule_submission_sections', [
            'schedule_submission_id' => $submission->id,
            'section_id' => $firstSection->id,
            'state' => 'withdrawn',
        ]);
        $this->assertDatabaseHas('schedule_submission_sections', [
            'schedule_submission_id' => $submission->id,
            'section_id' => $secondSection->id,
            'state' => 'included',
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $vpaa->id,
            'type' => 'schedule_withdrawn',
        ]);
        $history = ScheduleHistoryVersion::query()->where('action', 'schedule_withdrawn')->get();
        $this->assertCount(1, $history);
        $this->assertSame($first->id, $history->first()->schedule_id);
        $this->assertSame([$firstSection->id], $history->first()->changes['selected_section_ids']);
    }

    public function test_finalized_schedule_cannot_be_withdrawn(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $schedule = $this->schedule($department, $term, $room, $course, $firstSection, ['status' => 'finalized']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", ['section_ids' => [$firstSection->id]])
            ->assertStatus(422);

        $this->assertSame('finalized', $schedule->refresh()->status);
    }

    public function test_withdrawal_ignores_finalized_schedules_in_unselected_sections(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $withdrawn = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'submitted',
        ]);
        $finalized = $this->schedule($department, $term, $room, $course, $secondSection, [
            'status' => 'finalized',
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk()
            ->assertJsonPath('sections_unlocked', 1);

        $this->assertSame('revision', $withdrawn->refresh()->status);
        $this->assertSame('finalized', $finalized->refresh()->status);
    }

    public function test_finalized_section_status_is_not_downgraded_by_legacy_draft_rows(): void
    {
        [$department, $term, $room, $course, $firstSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $this->schedule($department, $term, $room, $course, $firstSection, ['status' => 'draft']);
        $this->schedule($department, $term, $room, $course, $firstSection, ['status' => 'finalized']);

        $response = $this->actingAs($secretary)
            ->getJson("/api/departments/{$department->id}/schedule-status")
            ->assertOk();

        $section = collect($response->json('sections'))->firstWhere('id', $firstSection->id);
        $this->assertSame('approved', $section['status']);
    }

    public function test_withdrawal_releases_instructors_only_for_the_withdrawn_sections(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $instructor = $this->instructor($department);

        $withdrawn = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);
        $untouched = $this->schedule($department, $term, $room, $course, $secondSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk()
            ->assertJsonPath('instructors_released', 1);

        // The section being revised loses its instructor: the schedule it was
        // made against is about to change, and no UI can reach the assignment
        // while the row sits outside the assignment statuses.
        $this->assertNull($withdrawn->refresh()->faculty_id);
        $this->assertSame('revision', $withdrawn->status);

        // The unselected approval cohort remains completely intact.
        $this->assertSame($instructor->id, $untouched->refresh()->faculty_id);
        $this->assertSame('faculty_assignment', $untouched->status);
    }

    public function test_withdrawn_section_can_complete_the_full_reapproval_workflow(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $schedule = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
        ]);
        $withdrawnSubmission = $this->submission($department, $term, [$firstSection], 'approved');
        $secondSection->update(['section_name' => 'BSIT 4A', 'year_level' => '4']);
        $finalized = $this->schedule($department, $term, $room, $course, $secondSection, [
            'status' => 'finalized',
        ]);
        $finalizedSubmission = $this->submission($department, $term, [$secondSection], 'approved', [
            'dean_reviewed_by' => $dean->id,
            'dean_reviewed_at' => now(),
            'vpaa_reviewed_by' => $vpaa->id,
            'vpaa_reviewed_at' => now(),
        ]);
        $finalizedUpdatedAt = $finalized->updated_at->toDateTimeString();

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk();

        $this->actingAs($secretary)
            ->patchJson('/api/schedules/batch-status', [
                'ids' => [$schedule->id],
                'status' => 'completed',
            ])
            ->assertOk();

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk()
            ->assertJsonPath('schedules_updated', 1);
        $this->assertSame('submitted', $schedule->refresh()->status);
        $this->assertSame('finalized', $finalized->refresh()->status);

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/approve-by-dean")
            ->assertOk();
        $this->assertSame('approved_by_dean', $schedule->refresh()->status);
        $this->assertSame('finalized', $finalized->refresh()->status);

        $this->actingAs($vpaa)
            ->postJson("/api/departments/{$department->id}/approve-by-vpaa")
            ->assertOk();
        $this->assertSame('faculty_assignment', $schedule->refresh()->status);
        $this->assertSame('finalized', $finalized->refresh()->status);
        $this->assertSame($finalizedUpdatedAt, $finalized->updated_at->toDateTimeString());
        $this->assertSame('withdrawn', $withdrawnSubmission->refresh()->status);
        $this->assertSame('approved', $finalizedSubmission->refresh()->status);
        $this->assertSame($dean->id, $finalizedSubmission->dean_reviewed_by);
        $this->assertSame($vpaa->id, $finalizedSubmission->vpaa_reviewed_by);

        $revisedSubmission = ScheduleSubmission::query()
            ->where('parent_submission_id', $withdrawnSubmission->id)
            ->latest('revision_number')
            ->firstOrFail();
        $this->assertSame('approved', $revisedSubmission->status);
        $this->assertSame($secretary->id, $revisedSubmission->submitted_by);
        $this->assertSame($dean->id, $revisedSubmission->dean_reviewed_by);
        $this->assertSame($vpaa->id, $revisedSubmission->vpaa_reviewed_by);

        $submissionAudit = SchedulingAuditLog::query()
            ->where('action', 'schedule_submitted')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame([$firstSection->id], $submissionAudit->metadata['selected_section_ids']);
        $this->assertSame(
            [$firstSection->id],
            SchedulingAuditLog::query()
                ->where('action', 'schedule_approved_by_dean')
                ->latest('id')
                ->firstOrFail()
                ->metadata['selected_section_ids'],
        );
        $this->assertSame(
            [$firstSection->id],
            SchedulingAuditLog::query()
                ->where('action', 'schedule_approved_by_vpaa')
                ->latest('id')
                ->firstOrFail()
                ->metadata['selected_section_ids'],
        );
    }

    public function test_initial_submission_cannot_skip_unfinished_year_levels(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $ready = $this->schedule($department, $term, $room, $course, $firstSection, ['status' => 'completed']);
        $draft = $this->schedule($department, $term, $room, $course, $secondSection, ['status' => 'draft']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertStatus(422);

        $this->assertSame('completed', $ready->refresh()->status);
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'schedule_submitted']);
    }

    public function test_released_instructors_are_recorded_in_the_audit_log(): void
    {
        [$department, $term, $room, $course, $firstSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $instructor = $this->instructor($department);

        $schedule = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk();

        $log = SchedulingAuditLog::query()
            ->where('action', 'instructor_assignment_released')
            ->where('section_id', $firstSection->id)
            ->firstOrFail();

        $this->assertSame($secretary->id, $log->user_id);
        $this->assertSame($department->id, $log->department_id);
        $this->assertSame('schedule_withdrawn', $log->metadata['reason']);
        $this->assertSame(1, $log->metadata['released_count']);
        $this->assertSame([$schedule->id], $log->metadata['schedule_ids']);
        $this->assertSame(
            $instructor->id,
            $log->metadata['previous_faculty_ids'][(string) $schedule->id],
        );
    }

    public function test_withdrawal_without_instructors_reports_none_released(): void
    {
        [$department, $term, $room, $course, $firstSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $this->schedule($department, $term, $room, $course, $firstSection, ['status' => 'submitted']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk()
            ->assertJsonPath('instructors_released', 0);

        $this->assertDatabaseHas('scheduling_audit_logs', [
            'action' => 'schedule_withdrawn',
            'department_id' => $department->id,
        ]);
        $this->assertDatabaseCount('scheduling_audit_logs', 1);
    }

    private function instructor(Departments $department): Faculty
    {
        return Faculty::create([
            'first_name' => 'Withdraw',
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'max_units' => 18,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
    }

    private function fixture(): array
    {
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
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
        $firstSection = Sections::create([
            'section_name' => 'BSIT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $secondSection = Sections::create([
            'section_name' => 'BSIT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        return [$department, $term, $room, $course, $firstSection, $secondSection];
    }

    private function schedule(Departments $department, Terms $term, Rooms $room, Course $course, Sections $section, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'term_id' => $term->id,
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

    private function submission(
        Departments $department,
        Terms $term,
        array $sections,
        string $status,
        array $overrides = [],
    ): ScheduleSubmission {
        $submission = ScheduleSubmission::create(array_merge([
            'department_id' => $department->id,
            'term_id' => $term->id,
            'revision_number' => ((int) ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('term_id', $term->id)
                ->max('revision_number')) + 1,
            'status' => $status,
            'submitted_at' => now(),
        ], $overrides));
        $submission->sections()->attach(
            collect($sections)->map(static fn (Sections $section): int => $section->id)->all(),
            ['state' => 'included'],
        );

        return $submission->load('sections');
    }
}
