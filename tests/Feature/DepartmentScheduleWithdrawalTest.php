<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Submission\SubmissionStatusResolver;
use App\Support\ApiCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartmentScheduleWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_can_withdraw_selected_sections_after_vpaa_approval(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        $first = $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
        ]);
        $second = $this->schedule($department, $semester, $room, $course, $secondSection, [
            // Another day: two sections in one room at one time is a conflict,
            // and approval refuses a package that still has one.
            'day' => 'Tuesday',
            'status' => 'faculty_assignment',
        ]);
        $submission = $this->submission($department, $semester, [$firstSection, $secondSection], 'approved', [
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
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'finalized']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", ['section_ids' => [$firstSection->id]])
            ->assertStatus(422);

        $this->assertSame('finalized', $schedule->refresh()->status);
    }

    public function test_withdrawal_ignores_finalized_schedules_in_unselected_sections(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $withdrawn = $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'submitted',
        ]);
        $finalized = $this->schedule($department, $semester, $room, $course, $secondSection, [
            // Another day: two sections in one room at one time is a conflict,
            // and approval refuses a package that still has one.
            'day' => 'Tuesday',
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
        [$department, $semester, $room, $course, $firstSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'draft']);
        $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'finalized']);

        $response = $this->actingAs($secretary)
            ->getJson("/api/departments/{$department->id}/schedule-status")
            ->assertOk();

        $section = collect($response->json('sections'))->firstWhere('id', $firstSection->id);
        $this->assertSame('approved', $section['status']);
    }

    public function test_withdrawal_keeps_instructors_on_every_section(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $instructor = $this->instructor($department);

        $withdrawn = $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);
        $untouched = $this->schedule($department, $semester, $room, $course, $secondSection, [
            // Another day: two sections in one room at one time is a conflict,
            // and approval refuses a package that still has one.
            'day' => 'Tuesday',
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk()
            ->assertJsonPath('instructors_released', 0);

        // The recalled section keeps its instructor; it is revalidated when
        // the schedule changes.
        $this->assertSame($instructor->id, $withdrawn->refresh()->faculty_id);
        $this->assertSame('revision', $withdrawn->status);

        // The unselected approval cohort remains completely intact.
        $this->assertSame($instructor->id, $untouched->refresh()->faculty_id);
        $this->assertSame('faculty_assignment', $untouched->status);
    }

    public function test_withdrawn_section_can_complete_the_full_reapproval_workflow(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
        ]);
        $withdrawnSubmission = $this->submission($department, $semester, [$firstSection], 'approved');
        $secondSection->update(['section_name' => 'BSIT 4A', 'year_level' => '4']);
        $finalized = $this->schedule($department, $semester, $room, $course, $secondSection, [
            // Another day: two sections in one room at one time is a conflict,
            // and approval refuses a package that still has one.
            'day' => 'Tuesday',
            'status' => 'finalized',
        ]);
        $finalizedSubmission = $this->submission($department, $semester, [$secondSection], 'approved', [
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
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $ready = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'completed']);
        $draft = $this->schedule($department, $semester, $room, $course, $secondSection, [
            // Another day: two sections in one room at one time is a conflict,
            // and approval refuses a package that still has one.
            'day' => 'Tuesday', 'status' => 'draft']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertStatus(422);

        $this->assertSame('completed', $ready->refresh()->status);
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'schedule_submitted']);
    }

    public function test_withdrawal_without_instructors_reports_none_released(): void
    {
        [$department, $semester, $room, $course, $firstSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'submitted']);

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

    /**
     * Reassignment no longer has to be emptied first: recalling keeps the
     * instructors, including one another college assigned to a delegated course.
     */
    public function test_a_reassignment_section_with_instructors_can_be_recalled(): void
    {
        [$department, $semester, $room, $course, $firstSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $otherCollege = Departments::create(['department_name' => 'Arts and Sciences', 'department_code' => 'CAS']);
        $delegatedInstructor = $this->instructor($otherCollege);

        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'reassignment',
            'faculty_id' => $delegatedInstructor->id,
            'faculty_assignment_done' => true,
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", ['section_ids' => [$firstSection->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Selected section schedules recalled for revision.');

        $schedule->refresh();
        $this->assertSame('revision', $schedule->status);
        $this->assertSame($delegatedInstructor->id, $schedule->faculty_id);
        $this->assertFalse((bool) $schedule->faculty_assignment_done);
    }

    /**
     * A withdrawn class leaves the assignment screens and the instructor's load,
     * so it must not keep blocking that instructor from other classes at the same
     * time -- nobody can see it to clear it.
     */
    public function test_a_withdrawn_class_no_longer_blocks_its_instructor_elsewhere(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $instructor = $this->instructor($department);
        $otherRoom = Rooms::create(['room_code' => 'CIT 102', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);
        $sameHour = $this->schedule($department, $semester, $otherRoom, $course, $secondSection, [
            // Another day: two sections in one room at one time is a conflict,
            // and approval refuses a package that still has one.
            'day' => 'Tuesday',
            'status' => 'faculty_assignment',
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", ['section_ids' => [$firstSection->id]])
            ->assertOk();

        $this->actingAs($secretary)
            ->patchJson("/api/instructor-assignments/{$sameHour->id}", ['faculty_id' => $instructor->id])
            ->assertOk();

        $this->assertSame($instructor->id, (int) $sameHour->refresh()->faculty_id);
    }

    public function test_a_returned_version_survives_reset_and_resubmission_keeps_both_versions(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        // Submission refuses to skip a year-level section with nothing scheduled.
        $secondSection->delete();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $dean = $this->grantCapabilities(User::factory()->create(['role' => 'dean', 'department_id' => $department->id]));
        $original = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'completed']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $first = ScheduleSubmission::query()->latest('id')->firstOrFail();
        $this->assertNotNull($first->snapshot_version_id);

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/return-by-dean", ['rejection_reason' => 'Move it.'])
            ->assertOk();

        // Reset clears only the working copy.
        $this->actingAs($secretary)
            ->postJson('/api/schedules/batch', ['operations' => [], 'delete_ids' => [$original->id]])
            ->assertOk();
        $this->assertNull(Schedule::find($original->id));

        $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$first->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.status', 'rejected_by_dean')
            ->assertJsonPath('data.rejection_reason', 'Move it.')
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', $original->id)
            ->assertJsonPath('data.schedules.0.day', 'Monday')
            ->assertJsonPath('data.schedules.0.course.course_code', 'IT 101')
            ->assertJsonPath('data.schedules.0.room.room_code', 'CIT 101');

        $revised = $this->schedule($department, $semester, $room, $course, $firstSection, [
            'status' => 'completed',
            'day' => 'Wednesday',
        ]);
        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $second = ScheduleSubmission::query()->latest('id')->firstOrFail();

        $this->assertSame($first->id, $second->parent_submission_id);
        $this->assertSame($first->revision_number + 1, $second->revision_number);
        $this->assertSame('rejected_by_dean', $first->refresh()->status);
        $this->assertNotSame($first->snapshot_version_id, $second->snapshot_version_id);

        $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$first->id}/snapshot")
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.day', 'Monday');
        $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$second->id}/snapshot")
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', $revised->id)
            ->assertJsonPath('data.schedules.0.day', 'Wednesday');
    }

    public function test_a_recalled_version_keeps_what_was_submitted_after_the_working_copy_is_edited(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        // Submission refuses to skip a year-level section with nothing scheduled.
        $secondSection->delete();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'completed']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $submission = ScheduleSubmission::query()->latest('id')->firstOrFail();
        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $schedule->refresh()->update(['day' => 'Friday']);

        $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$submission->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.schedules.0.day', 'Monday')
            ->assertJsonPath('data.schedules.0.status', 'submitted');
    }

    public function test_submission_and_revision_status_follow_a_section_through_return_reset_and_resubmit(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secondSection->delete();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $dean = $this->grantCapabilities(User::factory()->create(['role' => 'dean', 'department_id' => $department->id]));
        $original = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'completed']);

        $this->assertSectionStatus($secretary, $firstSection, 'draft', 'initial');

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $this->assertSectionStatus($secretary, $firstSection, 'submitted', 'initial');

        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/return-by-dean", ['rejection_reason' => 'Move it.'])
            ->assertOk();
        $this->assertSectionStatus($secretary, $firstSection, 'rejected', 'initial');

        // Editing the working copy marks it modified; the submission stays rejected.
        $original->refresh()->update(['day' => 'Thursday']);
        $this->editedDirectly();
        $this->assertSectionStatus($secretary, $firstSection, 'rejected', 'modified');

        // A Reset that leaves nothing is shown as reset, not modified.
        $this->actingAs($secretary)
            ->postJson('/api/schedules/batch', ['operations' => [], 'delete_ids' => [$original->id]])
            ->assertOk();
        $this->assertSectionStatus($secretary, $firstSection, 'rejected', 'reset');

        $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'completed', 'day' => 'Wednesday']);
        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $this->assertSectionStatus($secretary, $firstSection, 'submitted', 'modified');

        $submissions = collect($this->actingAs($secretary)->getJson('/api/initial-data')->json('schedule_submissions'))
            ->keyBy('revision_number');
        $this->assertSame('rejected_by_dean', $submissions[1]['status']);
        $this->assertSame('initial', $submissions[1]['revision_status']);
        $this->assertSame('pending_dean', $submissions[2]['status']);
        $this->assertSame('modified', $submissions[2]['revision_status']);
    }

    public function test_a_returned_versions_fingerprints_are_cached_once_and_survive_later_edits(): void
    {
        [, , , , $section, $secretary, $schedule, $submission] = $this->returnedVersion();
        $submittedKey = SubmissionStatusResolver::meetingKey($schedule->getAttributes());
        $cacheKey = "submission.fingerprints.v1.{$submission->snapshot_version_id}";

        $this->assertSectionStatus($secretary, $section, 'rejected', 'initial');
        $this->assertSame([$section->id => $submittedKey], Cache::get($cacheKey));

        $schedule->update(['day' => 'Thursday']);
        $this->editedDirectly();
        $this->assertSectionStatus($secretary, $section, 'rejected', 'modified');
        // The entry is the submitted version, not the working copy.
        $this->assertSame([$section->id => $submittedKey], Cache::get($cacheKey));

        // A later read compares against the cached entry without decoding the snapshot again.
        DB::enableQueryLog();
        $statuses = app(SubmissionStatusResolver::class)->forSections([$section->id], $submission->semester_id);
        $this->assertSame('modified', $statuses[$section->id]['revision_status']);
        $this->assertFalse(collect(DB::getQueryLog())->contains(
            fn (array $query): bool => str_contains($query['query'], 'schedule_history_items'),
        ));
    }

    public function test_a_version_with_no_sections_left_is_skipped_without_reading_its_snapshot(): void
    {
        [, , , , , , , $submission] = $this->returnedVersion();
        $submission->sections()->detach();
        Cache::flush();

        $statuses = app(SubmissionStatusResolver::class)->forSubmissions(ScheduleSubmission::query()->with('sections')->get());

        $this->assertSame([$submission->id => 'initial'], $statuses);
        $this->assertFalse(Cache::has("submission.fingerprints.v1.{$submission->snapshot_version_id}"));
    }

    public function test_revision_diff_lists_moved_meetings_against_the_rejected_version_and_ignores_instructors(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secondSection->delete();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $dean = $this->grantCapabilities(User::factory()->create(['role' => 'dean', 'department_id' => $department->id]));
        $moved = $this->schedule($department, $semester, $room, $course, $firstSection);
        $kept = $this->schedule($department, $semester, $room, $course, $firstSection, ['day' => 'Tuesday']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/return-by-dean", ['rejection_reason' => 'Move Monday.'])
            ->assertOk();

        $moved->refresh()->update(['day' => 'Wednesday', 'status' => 'completed']);
        // Neither an instructor nor the pair-level hybrid flag is a change to this meeting.
        $kept->refresh()->update(['faculty_id' => $this->instructor($department)->id, 'is_hybrid' => ! $kept->is_hybrid, 'status' => 'completed']);
        $this->editedDirectly();
        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $resubmitted = ScheduleSubmission::query()->latest('id')->firstOrFail();

        $sections = $this->actingAs($dean)
            ->getJson("/api/schedule-submissions/{$resubmitted->id}/revision-diff")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->json('data.sections');

        $this->assertCount(1, $sections);
        $this->assertSame('Move Monday.', $sections[0]['previous_rejection_reason']);
        $this->assertCount(1, $sections[0]['changes']);
        $this->assertSame('changed', $sections[0]['changes'][0]['change']);
        $this->assertSame('Monday', $sections[0]['changes'][0]['before']['day']);
        $this->assertSame('Wednesday', $sections[0]['changes'][0]['after']['day']);
    }

    public function test_a_recalled_section_reads_recalled_and_initial_until_it_is_changed(): void
    {
        [$department, $semester, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secondSection->delete();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $schedule = $this->schedule($department, $semester, $room, $course, $firstSection, ['status' => 'completed']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", ['section_ids' => [$firstSection->id]])
            ->assertOk();
        $this->assertSectionStatus($secretary, $firstSection, 'recalled', 'initial');

        $schedule->refresh()->update(['start_time' => '10:00', 'end_time' => '11:00']);
        $this->editedDirectly();
        $this->assertSectionStatus($secretary, $firstSection, 'recalled', 'modified');
    }

    public function test_edits_removals_and_additions_to_a_returned_version_are_recorded_with_their_before_state(): void
    {
        [$department, $semester, $room, $course, $section, $secretary, $schedule, $submission] = $this->returnedVersion();

        // Edit reopens a returned section for revision first; a status change alone is not a revision.
        $this->actingAs($secretary)
            ->patchJson('/api/schedules/batch-status', ['ids' => [$schedule->id], 'status' => 'revision'])
            ->assertOk();
        $this->actingAs($secretary)
            ->patchJson("/api/schedules/{$schedule->id}", ['day' => 'Thursday'])
            ->assertOk();
        $secondCourse = $course->replicate()->fill(['course_code' => 'IT 102', 'course_name' => 'Databases']);
        $secondCourse->save();
        $added = $this->actingAs($secretary)
            ->postJson('/api/schedules/batch', ['operations' => [[
                'semester_id' => $semester->id,
                'section_id' => $section->id,
                'course_id' => $secondCourse->id,
                'room_id' => $room->id,
                'department_id' => $department->id,
                'day' => 'Friday',
                'start_time' => '13:00',
                'end_time' => '14:00',
                'mode' => 'on-site',
            ]]])
            ->assertOk()
            ->json('schedules.0.id');
        $this->actingAs($secretary)->deleteJson("/api/schedules/{$schedule->id}")->assertOk();

        $entries = $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$submission->id}/changes")
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $entries);
        $this->assertSame(['update', 'batch', 'delete'], array_column($entries, 'operation'));
        $this->assertSame('updated', $entries[0]['changes'][0]['change']);
        $this->assertSame('Monday', $entries[0]['changes'][0]['before']['day']);
        $this->assertSame('Thursday', $entries[0]['changes'][0]['after']['day']);
        $this->assertSame('IT 101', $entries[0]['changes'][0]['before']['course']['course_code']);
        $this->assertSame('added', $entries[1]['changes'][0]['change']);
        $this->assertSame($added, $entries[1]['changes'][0]['schedule_id']);
        $this->assertSame('removed', $entries[2]['changes'][0]['change']);
        $this->assertSame('Thursday', $entries[2]['changes'][0]['before']['day']);
        $this->assertNull($entries[2]['changes'][0]['after']);
        $this->assertSame($secretary->id, $entries[0]['actor']['id']);
    }

    public function test_a_course_edit_is_recorded_and_the_submitted_version_keeps_its_old_details(): void
    {
        [, , , $course, , $secretary, , $submission] = $this->returnedVersion();

        $this->actingAs($secretary)
            ->patchJson("/api/courses/{$course->id}", ['course_name' => 'Advanced Programming', 'units' => 3, 'lecture_hours' => 3])
            ->assertOk();

        $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$submission->id}/snapshot")
            ->assertJsonPath('data.schedules.0.course.course_name', 'Programming')
            ->assertJsonPath('data.schedules.0.course.units', 1);

        $entry = $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$submission->id}/changes")
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $this->assertSame('revision_course_changed', $entry['action']);
        $this->assertEquals(['before' => 'Programming', 'after' => 'Advanced Programming'], $entry['course_changes']['course_name']);
        $this->assertEquals(['before' => 1, 'after' => 3], $entry['course_changes']['units']);
        $this->assertSame('Programming', $entry['changes'][0]['before']['course']['course_name']);
        $this->assertSame('Advanced Programming', $entry['changes'][0]['after']['course']['course_name']);
    }

    public function test_a_deleted_section_stays_in_the_submitted_version_and_its_history(): void
    {
        [, , , , $section, $secretary, $schedule, $submission] = $this->returnedVersion();

        $this->actingAs($secretary)->deleteJson("/api/sections/{$section->id}")->assertOk();
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);

        $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$submission->id}/snapshot")
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', $schedule->id)
            ->assertJsonPath('data.schedules.0.section.section_name', 'BSIT 1A');

        $entry = $this->actingAs($secretary)
            ->getJson("/api/schedule-submissions/{$submission->id}/changes")
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $this->assertSame('revision_section_deleted', $entry['action']);
        $this->assertSame('BSIT 1A', $entry['section']['section_name']);
        $this->assertSame('removed', $entry['changes'][0]['change']);
        $this->assertSame('BSIT 1A', $entry['changes'][0]['before']['section']['section_name']);
    }

    public function test_drafting_a_never_submitted_section_records_no_revision_history(): void
    {
        [$department, $semester, $room, $course, $section, $secondSection] = $this->fixture();
        $secondSection->delete();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $schedule = $this->schedule($department, $semester, $room, $course, $section, ['status' => 'draft']);

        $this->actingAs($secretary)->patchJson("/api/schedules/{$schedule->id}", ['day' => 'Thursday'])->assertOk();

        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'revision_schedules_changed']);
    }

    /** A section submitted and returned by the Dean, with one class. */
    private function returnedVersion(): array
    {
        [$department, $semester, $room, $course, $section, $secondSection] = $this->fixture();
        $secondSection->delete();
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
        $dean = $this->grantCapabilities(User::factory()->create(['role' => 'dean', 'department_id' => $department->id]));
        $schedule = $this->schedule($department, $semester, $room, $course, $section, ['status' => 'completed']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/submit-schedules", ['section_ids' => [$section->id]])
            ->assertOk();
        $this->actingAs($dean)
            ->postJson("/api/departments/{$department->id}/return-by-dean", ['rejection_reason' => 'Move it.'])
            ->assertOk();

        return [$department, $semester, $room, $course, $section, $secretary, $schedule->refresh(), ScheduleSubmission::query()->latest('id')->firstOrFail()];
    }

    /** The schedule endpoints drop the cached /initial-data on every write; a direct model edit must too. */
    private function editedDirectly(): void
    {
        ApiCache::forgetGroups(['initial.data']);
    }

    private function assertSectionStatus(User $user, Sections $section, string $submission, string $revision): void
    {
        $row = collect($this->actingAs($user)->getJson('/api/initial-data')->assertOk()->json('sections'))
            ->firstWhere('id', $section->id);
        $this->assertNotNull($row);
        $this->assertSame(
            [$submission, $revision],
            [$row['submission_status'], $row['revision_status']],
        );
    }

    public function test_another_department_cannot_read_a_submission_snapshot(): void
    {
        [$department, $semester, , , $firstSection] = $this->fixture();
        $other = Departments::create(['department_name' => 'Nursing', 'department_code' => 'CON']);
        Program::create(['department_id' => $other->id, 'code' => 'BSN', 'name' => 'Nursing']);
        $outsider = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $other->id]));
        $submission = $this->submission($department, $semester, [$firstSection], 'withdrawn');

        $this->actingAs($outsider)
            ->getJson("/api/schedule-submissions/{$submission->id}/snapshot")
            ->assertForbidden();
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
        // Schedule capabilities and section scheduling both require the
        // department to own a program.
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
        $firstSection = Sections::create([
            'section_name' => 'BSIT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $secondSection = Sections::create([
            'section_name' => 'BSIT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        return [$department, $semester, $room, $course, $firstSection, $secondSection];
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

    private function submission(
        Departments $department,
        Semester $semester,
        array $sections,
        string $status,
        array $overrides = [],
    ): ScheduleSubmission {
        $submission = ScheduleSubmission::create(array_merge([
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'revision_number' => ((int) ScheduleSubmission::query()
                ->where('department_id', $department->id)
                ->where('semester_id', $semester->id)
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
