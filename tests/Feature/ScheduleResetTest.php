<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSplit;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Permanent Reset (`POST /api/schedules/reset`): plotting-editable sections only (draft, completed,
 * revision), judged per section, server-resolved, all-or-nothing, and fully independent of
 * Schedule History, submissions, and approvals.
 */
class ScheduleResetTest extends TestCase
{
    use RefreshDatabase;

    private const PRESERVED_TABLES = [
        'schedule_history_versions', 'schedule_history_items', 'schedule_submissions',
        'schedule_submission_sections', 'sections', 'courses', 'faculties', 'rooms', 'semesters',
        'curriculum', 'departments', 'programs',
    ];

    public function test_reset_permanently_deletes_drafts_and_every_split_row_without_touching_history(): void
    {
        $f = $this->fixture();
        $group = (string) Str::uuid();
        $lecture = $this->schedule($f, $f['courseA'], ['split_group_id' => $group, 'meeting_type' => 'lecture', 'meeting_index' => 1, 'faculty_id' => $f['faculty']->id]);
        $lab = $this->schedule($f, $f['courseA'], ['day' => 'Wednesday', 'split_group_id' => $group, 'meeting_type' => 'laboratory', 'meeting_index' => 2, 'faculty_id' => $f['faculty']->id]);
        $plain = $this->schedule($f, $f['courseB'], ['day' => 'Tuesday']);
        // A soft-deleted split child left behind on a live draft must go too.
        $retired = ScheduleSplit::create(['schedule_id' => $plain->id, 'split_group_id' => (string) Str::uuid(), 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $retired->delete();
        $this->historyFor($f, [$lecture, $lab, $plain]);
        $before = $this->snapshot();
        $auditBefore = SchedulingAuditLog::query()->orderBy('id')->get()->toArray();

        $preview = $this->actingAs($f['user'])->postJson('/api/schedules/reset/preview', [
            'semester_id' => $f['semester']->id,
            'section_ids' => [$f['section']->id],
        ])->assertOk()
            ->assertJsonPath('counts.schedules', 3)
            ->assertJsonPath('counts.classes', 2)
            ->assertJsonPath('counts.meeting_splits', 3)
            ->assertJsonPath('counts.archived_meeting_splits', 1)
            ->assertJsonPath('counts.instructor_assignments', 1)
            ->assertJsonPath('sections.0.schedules', 3)
            ->assertJsonPath('semester.id', $f['semester']->id);
        $this->assertSame($before, $this->snapshot(), 'Preview must be read-only.');

        $this->actingAs($f['user'])->postJson('/api/schedules/reset', [
            'semester_id' => $f['semester']->id,
            'section_ids' => [$f['section']->id],
            'expected_token' => $preview->json('token'),
        ])->assertOk()->assertJsonPath('counts.schedules', 3);

        foreach ([$lecture, $lab, $plain] as $schedule) {
            $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
        }
        $this->assertSame(0, ScheduleSplit::withTrashed()->whereIn('schedule_id', [$lecture->id, $lab->id, $plain->id])->count());
        $this->assertSame($before, $this->snapshot(), 'History, submissions, and master data must be unchanged.');
        $this->assertSame($auditBefore, SchedulingAuditLog::query()->where('action', '!=', 'schedule_reset')->orderBy('id')->get()->toArray());
        $log = SchedulingAuditLog::query()->where('action', 'schedule_reset')->sole();
        $this->assertNull($log->history_version_id);
        $this->assertTrue($log->metadata['permanent']);
        $this->assertSame(3, $log->metadata['schedules']);
        $this->assertDatabaseMissing('schedule_history_versions', ['source' => 'batch_delete']);
        $this->assertDatabaseHas('schedule_history_items', ['original_schedule_id' => $lecture->id]);
    }

    public function test_reset_leaves_other_semesters_unselected_sections_and_intentional_archives_unchanged(): void
    {
        $f = $this->fixture();
        $target = $this->schedule($f, $f['courseA']);
        $archived = $this->schedule($f, $f['courseB'], ['day' => 'Friday', 'split_group_id' => (string) Str::uuid(), 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $archived->delete();
        $otherSection = $this->section($f, 'A2');
        $unselected = $this->schedule($f, $f['courseA'], ['section_id' => $otherSection->id, 'day' => 'Thursday']);
        $pastSemester = Semester::create(['academic_year' => '2025-2026', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);
        $pastSection = $this->section($f, 'A1', $pastSemester);
        $past = $this->schedule($f, $f['courseA'], ['semester_id' => $pastSemester->id, 'section_id' => $pastSection->id]);

        $this->reset($f, [$f['section']->id])->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $target->id]);
        $this->assertSoftDeleted('schedules', ['id' => $archived->id]);
        $this->assertSame(1, ScheduleSplit::onlyTrashed()->where('schedule_id', $archived->id)->count());
        $this->assertNotSoftDeleted('schedules', ['id' => $unselected->id]);
        $this->assertNotSoftDeleted('schedules', ['id' => $past->id]);
        $this->assertDatabaseHas('sections', ['id' => $f['section']->id]);
        $this->assertDatabaseHas('courses', ['id' => $f['courseA']->id, 'deleted_at' => null]);
    }

    public function test_editable_sections_reset_whatever_their_submission_history(): void
    {
        $f = $this->fixture();
        // Still being prepared, completed but never submitted, and a section mixing both.
        $draft = $this->schedule($f, $f['courseA']);
        $completedSection = $this->section($f, 'A2');
        $completed = $this->schedule($f, $f['courseA'], ['section_id' => $completedSection->id, 'status' => 'completed']);
        // Returned by the Dean; its old submission stays on record.
        $returnedSection = $this->section($f, 'A3');
        $returned = $this->schedule($f, $f['courseA'], ['section_id' => $returnedSection->id, 'status' => 'rejected_by_dean']);
        $returnedSubmission = $this->submission($f, 'rejected_by_dean', [$returnedSection->id => 'included']);
        // Fully recalled from the VPAA queue.
        $recalledSection = $this->section($f, 'A4');
        $recalled = $this->schedule($f, $f['courseA'], ['section_id' => $recalledSection->id, 'status' => 'revision']);
        $recalledSubmission = $this->submission($f, 'withdrawn', [$recalledSection->id => 'withdrawn']);
        $mixed = $this->schedule($f, $f['courseB'], ['status' => 'completed', 'day' => 'Tuesday']);
        $this->historyFor($f, [$draft, $completed, $returned, $recalled]);
        $before = $this->snapshot();

        $ids = [$f['section']->id, $completedSection->id, $returnedSection->id, $recalledSection->id];
        $this->reset($f, $ids)->assertOk()->assertJsonPath('counts.schedules', 5)
            ->assertJsonPath('message', 'Schedules were permanently deleted. Schedule History was not changed.');

        foreach ([$draft, $completed, $returned, $recalled, $mixed] as $schedule) {
            $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
        }
        $this->assertSame($before, $this->snapshot(), 'Submissions, reviewers, and Schedule History must be unchanged.');
        $this->assertDatabaseHas('schedule_submissions', ['id' => $returnedSubmission->id, 'status' => 'rejected_by_dean']);
        $this->assertDatabaseHas('schedule_submissions', ['id' => $recalledSubmission->id, 'status' => 'withdrawn']);
    }

    public function test_reset_presentation_survives_replotting_and_ends_at_the_next_submission(): void
    {
        $this->freezeTime();
        $f = $this->fixture();
        $otherSection = $this->section($f, 'A2');
        $this->schedule($f, $f['courseA']);
        $this->schedule($f, $f['courseA'], ['section_id' => $otherSection->id, 'day' => 'Tuesday']);
        $dean = $this->grantCapabilities(User::factory()->create(['role' => 'dean', 'department_id' => $f['department']->id]));
        $url = "/api/departments/{$f['department']->id}/submit-schedules";
        $selection = ['section_ids' => [$f['section']->id, $otherSection->id]];
        $this->actingAs($f['user'])->postJson($url, $selection)->assertOk();
        $this->actingAs($dean)->postJson("/api/departments/{$f['department']->id}/return-by-dean", [
            'rejection_reason' => 'Correct the first section.',
        ])->assertOk();
        $returned = ScheduleSubmission::query()->latest('id')->firstOrFail();
        $historicalSubmission = $returned->getAttributes();
        $readSections = fn () => collect($this->actingAs($f['user'])->getJson('/api/initial-data')->assertOk()->json('sections'))->keyBy('id');
        $this->assertFalse($readSections()[$f['section']->id]['schedule_reset_since_submission']);

        $this->reset($f, [$f['section']->id])->assertOk();
        $sections = $readSections();
        $this->assertTrue($sections[$f['section']->id]['schedule_reset_since_submission']);
        $this->assertSame('rejected', $sections[$f['section']->id]['submission_status']);
        $this->assertSame('reset', $sections[$f['section']->id]['revision_status']);
        $this->assertFalse($sections[$otherSection->id]['schedule_reset_since_submission']);

        $this->schedule($f, $f['courseA'], ['day' => 'Thursday']);
        \App\Support\ApiCache::forgetGroups(['initial.data']);
        $this->assertTrue($readSections()[$f['section']->id]['schedule_reset_since_submission']);
        $this->actingAs($f['user'])->postJson($url, $selection)->assertOk();
        $sections = $readSections();
        $this->assertFalse($sections[$f['section']->id]['schedule_reset_since_submission']);
        $this->assertSame('submitted', $sections[$f['section']->id]['submission_status']);
        $this->assertSame($historicalSubmission, $returned->fresh()->getAttributes());

        $past = Semester::create(['academic_year' => '2025-2026', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        SchedulingAuditLog::create([
            'semester_id' => $past->id, 'section_id' => $f['section']->id,
            'action' => 'schedule_reset', 'metadata' => ['section_ids' => [$f['section']->id]], 'created_at' => now(),
        ]);
        \App\Support\ApiCache::forgetGroups(['initial.data']);
        $this->assertFalse($readSections()[$f['section']->id]['schedule_reset_since_submission']);
    }

    public function test_a_recalled_section_resets_while_the_rest_of_its_submission_stays_under_review(): void
    {
        $f = $this->fixture();
        $recalled = $this->schedule($f, $f['courseA'], ['status' => 'revision']);
        $underReview = $this->section($f, 'A2');
        $pending = $this->schedule($f, $f['courseA'], ['section_id' => $underReview->id, 'status' => 'approved_by_dean']);
        $submission = $this->submission($f, 'partially_withdrawn', [$f['section']->id => 'withdrawn', $underReview->id => 'included']);

        // Together: the section still under review blocks the request, with its own reason.
        $this->preview($f, [$f['section']->id, $underReview->id], 422)
            ->assertJsonPath('code', 'reset_protected')
            ->assertJsonPath('section_ids', [$underReview->id])
            ->assertJsonPath('blocked_sections.0.reason', 'is waiting for VPAA review');
        $this->assertNotSoftDeleted('schedules', ['id' => $recalled->id]);

        // Alone: the recalled section is judged on its own and resets.
        $this->reset($f, [$f['section']->id])->assertOk()->assertJsonPath('counts.schedules', 1);
        $this->assertDatabaseMissing('schedules', ['id' => $recalled->id]);
        $this->assertDatabaseHas('schedules', ['id' => $pending->id, 'status' => 'approved_by_dean', 'deleted_at' => null]);
        $this->assertDatabaseHas('schedule_submissions', ['id' => $submission->id, 'status' => 'partially_withdrawn']);
        $this->assertDatabaseHas('schedule_submission_sections', ['schedule_submission_id' => $submission->id, 'section_id' => $f['section']->id, 'state' => 'withdrawn']);
        $this->assertDatabaseHas('schedule_submission_sections', ['schedule_submission_id' => $submission->id, 'section_id' => $underReview->id, 'state' => 'included']);
    }

    public function test_protected_stages_reject_the_whole_reset_with_a_specific_reason(): void
    {
        $cases = [
            'submitted' => 'is waiting for Dean review',
            'approved_by_dean' => 'is waiting for VPAA review',
            'conditionally_approved' => 'is waiting for VPAA review',
            'approved' => 'is approved by the VPAA and locked for instructor assignment',
            'faculty_assignment' => 'is approved by the VPAA and locked for instructor assignment',
            'reassignment' => 'is approved by the VPAA and locked for instructor assignment',
            'finalized' => 'is approved by the VPAA and locked for instructor assignment',
        ];
        foreach ($cases as $status => $reason) {
            $f = $this->fixture($status);
            $editable = $this->schedule($f, $f['courseA'], ['status' => 'revision']);
            $clean = $this->section($f, 'A2');
            $cleanDraft = $this->schedule($f, $f['courseA'], ['section_id' => $clean->id, 'day' => 'Thursday']);
            $protected = $this->schedule($f, $f['courseB'], ['day' => 'Tuesday', 'status' => $status]);

            $response = $this->preview($f, [$clean->id, $f['section']->id], 422)
                ->assertJsonPath('code', 'reset_protected')
                ->assertJsonPath('section_ids', [$f['section']->id])
                ->assertJsonPath('blocked_sections.0.reason', $reason);
            $this->assertStringContainsString("Section A1 {$reason}", $response->json('message'), $status);
            $this->assertStringNotContainsString('only deletes draft', $response->json('message'));
            $this->actingAs($f['user'])->postJson('/api/schedules/reset', [
                'semester_id' => $f['semester']->id,
                'section_ids' => [$clean->id, $f['section']->id],
                'expected_token' => str_repeat('a', 64),
            ])->assertStatus(422)->assertJsonPath('code', 'reset_protected');

            foreach ([$editable, $cleanDraft, $protected] as $schedule) {
                $this->assertNotSoftDeleted('schedules', ['id' => $schedule->id]);
            }
        }
    }

    public function test_mixed_selection_names_every_blocked_section_and_deletes_nothing(): void
    {
        $f = $this->fixture();
        $eligible = $this->schedule($f, $f['courseA'], ['status' => 'completed']);
        $pendingDean = $this->section($f, 'A2');
        $this->schedule($f, $f['courseA'], ['section_id' => $pendingDean->id, 'status' => 'submitted']);
        $this->submission($f, 'pending_dean', [$pendingDean->id => 'included']);
        $approved = $this->section($f, 'A3');
        $this->schedule($f, $f['courseA'], ['section_id' => $approved->id, 'status' => 'faculty_assignment']);
        $this->submission($f, 'approved', [$approved->id => 'included']);
        $before = Schedule::query()->count();

        $response = $this->preview($f, [$f['section']->id, $pendingDean->id, $approved->id], 422)
            ->assertJsonPath('section_ids', [$pendingDean->id, $approved->id])
            ->assertJsonPath('blocked_sections.0.section_name', 'A2')
            ->assertJsonPath('blocked_sections.0.reason', 'is waiting for Dean review')
            ->assertJsonPath('blocked_sections.1.reason', 'is approved by the VPAA and locked for instructor assignment');
        $this->assertStringContainsString('Deselect them', $response->json('message'));

        $this->assertSame($before, Schedule::query()->count());
        $this->assertNotSoftDeleted('schedules', ['id' => $eligible->id]);
    }

    public function test_a_submission_claim_blocks_reset_even_when_rows_look_editable(): void
    {
        $f = $this->fixture();
        $draft = $this->schedule($f, $f['courseA'], ['status' => 'completed']);
        $this->submission($f, 'pending_dean', [$f['section']->id => 'included']);

        $this->preview($f, [$f['section']->id], 422)
            ->assertJsonPath('blocked_sections.0.reason', 'is part of a submission waiting for Dean review');
        $this->assertNotSoftDeleted('schedules', ['id' => $draft->id]);
    }

    public function test_eligibility_is_rechecked_when_the_reset_is_confirmed(): void
    {
        // Eligible at review, submitted before confirmation: blocked, nothing deleted.
        $f = $this->fixture();
        $completed = $this->schedule($f, $f['courseA'], ['status' => 'completed']);
        $token = $this->preview($f, [$f['section']->id])->json('token');
        $completed->update(['status' => 'submitted']);
        $this->submission($f, 'pending_dean', [$f['section']->id => 'included']);

        $this->reset($f, [$f['section']->id], $token)->assertStatus(422)
            ->assertJsonPath('code', 'reset_protected')
            ->assertJsonPath('blocked_sections.0.reason', 'is waiting for Dean review');
        $this->assertDatabaseHas('schedules', ['id' => $completed->id, 'status' => 'submitted', 'deleted_at' => null]);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'schedule_reset']);

        // Blocked while under review, eligible once recalled for revision.
        $g = $this->fixture('recall');
        $submitted = $this->schedule($g, $g['courseA'], ['status' => 'submitted']);
        $submission = $this->submission($g, 'pending_dean', [$g['section']->id => 'included']);
        $this->preview($g, [$g['section']->id], 422);
        $submitted->update(['status' => 'revision']);
        $submission->sections()->updateExistingPivot($g['section']->id, ['state' => 'withdrawn']);
        $submission->update(['status' => 'withdrawn']);

        $this->reset($g, [$g['section']->id])->assertOk();
        $this->assertDatabaseMissing('schedules', ['id' => $submitted->id]);
        $this->assertDatabaseHas('schedule_submissions', ['id' => $submission->id, 'status' => 'withdrawn']);
    }

    public function test_a_stale_review_is_rejected_and_nothing_is_deleted(): void
    {
        $f = $this->fixture();
        $first = $this->schedule($f, $f['courseA']);
        $token = $this->preview($f, [$f['section']->id])->json('token');

        // The reviewed target changes: a new draft class appears, then an instructor changes.
        $added = $this->schedule($f, $f['courseB'], ['day' => 'Tuesday']);
        $this->actingAs($f['user'])->postJson('/api/schedules/reset', [
            'semester_id' => $f['semester']->id,
            'section_ids' => [$f['section']->id],
            'expected_token' => $token,
        ])->assertStatus(409)->assertJsonPath('code', 'reset_target_changed')->assertJsonPath('preview.counts.schedules', 2);

        $refreshed = $this->preview($f, [$f['section']->id])->json('token');
        $first->update(['faculty_id' => $f['faculty']->id]);
        $this->actingAs($f['user'])->postJson('/api/schedules/reset', [
            'semester_id' => $f['semester']->id,
            'section_ids' => [$f['section']->id],
            'expected_token' => $refreshed,
        ])->assertStatus(409);

        $this->assertNotSoftDeleted('schedules', ['id' => $first->id]);
        $this->assertNotSoftDeleted('schedules', ['id' => $added->id]);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'schedule_reset']);
    }

    public function test_a_submission_between_review_and_confirm_blocks_the_reset(): void
    {
        $f = $this->fixture();
        $draft = $this->schedule($f, $f['courseA']);
        $token = $this->preview($f, [$f['section']->id])->json('token');

        $draft->update(['status' => 'submitted']);
        $submission = ScheduleSubmission::create([
            'department_id' => $f['department']->id, 'semester_id' => $f['semester']->id,
            'revision_number' => 1, 'status' => 'pending_dean', 'submitted_at' => now(),
        ]);
        $submission->sections()->attach([$f['section']->id], ['state' => 'included']);

        $this->actingAs($f['user'])->postJson('/api/schedules/reset', [
            'semester_id' => $f['semester']->id,
            'section_ids' => [$f['section']->id],
            'expected_token' => $token,
        ])->assertStatus(422)->assertJsonPath('code', 'reset_protected');

        $this->assertDatabaseHas('schedules', ['id' => $draft->id, 'status' => 'submitted', 'deleted_at' => null]);
    }

    public function test_a_section_claimed_by_an_open_submission_cannot_be_reset_even_with_draft_rows(): void
    {
        $f = $this->fixture();
        $draft = $this->schedule($f, $f['courseA']);
        $submission = ScheduleSubmission::create([
            'department_id' => $f['department']->id, 'semester_id' => $f['semester']->id,
            'revision_number' => 1, 'status' => 'pending_vpaa', 'submitted_at' => now(),
        ]);
        $submission->sections()->attach([$f['section']->id], ['state' => 'included']);

        $this->preview($f, [$f['section']->id], 422)->assertJsonPath('code', 'reset_protected');
        $this->assertNotSoftDeleted('schedules', ['id' => $draft->id]);
    }

    public function test_unauthorized_and_out_of_scope_requests_fail_without_deleting(): void
    {
        $f = $this->fixture();
        $draft = $this->schedule($f, $f['courseA']);

        $otherDepartment = Departments::create(['department_name' => 'Department B', 'department_code' => 'DEPB']);
        Program::create(['department_id' => $otherDepartment->id, 'code' => 'PB', 'name' => 'Program B']);
        $outsider = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $otherDepartment->id]));
        $this->actingAs($outsider)->postJson('/api/schedules/reset/preview', ['semester_id' => $f['semester']->id, 'section_ids' => [$f['section']->id]])->assertForbidden();

        // A Dean of the same department views schedules but holds no schedule.delete capability.
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $f['department']->id]);
        $this->actingAs($dean)->postJson('/api/schedules/reset/preview', ['semester_id' => $f['semester']->id, 'section_ids' => [$f['section']->id]])->assertForbidden();

        // The program is owned by an active Program Head, so the department Secretary cannot write it.
        User::factory()->create(['role' => 'program_head', 'department_id' => $f['department']->id, 'program_id' => $f['program']->id, 'is_active' => true]);
        $token = str_repeat('b', 64);
        $this->actingAs($f['user'])->postJson('/api/schedules/reset', ['semester_id' => $f['semester']->id, 'section_ids' => [$f['section']->id], 'expected_token' => $token])->assertForbidden();

        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $this->actingAs($vpaa)->postJson('/api/schedules/reset/preview', ['semester_id' => $f['semester']->id, 'section_ids' => [$f['section']->id]])->assertForbidden();

        $this->assertNotSoftDeleted('schedules', ['id' => $draft->id]);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $f = $this->fixture();
        $this->schedule($f, $f['courseA']);
        $empty = $this->section($f, 'A2');

        $this->actingAs($f['user'])->postJson('/api/schedules/reset/preview', ['semester_id' => $f['semester']->id])->assertJsonValidationErrors('section_ids');
        $this->actingAs($f['user'])->postJson('/api/schedules/reset', ['semester_id' => $f['semester']->id, 'section_ids' => [$f['section']->id]])->assertJsonValidationErrors('expected_token');
        $this->preview($f, [$f['section']->id, $empty->id], 422)->assertJsonPath('code', 'reset_scope_invalid');
        $this->preview($f, [999999], 422)->assertJsonPath('code', 'reset_scope_invalid');

        $past = Semester::create(['academic_year' => '2025-2026', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);
        $this->actingAs($f['user'])->postJson('/api/schedules/reset/preview', ['semester_id' => $past->id, 'section_ids' => [$f['section']->id]])
            ->assertStatus(422)->assertJsonPath('code', 'reset_scope_invalid');
    }

    public function test_a_failure_after_deleting_rolls_everything_back(): void
    {
        $f = $this->fixture();
        $group = (string) Str::uuid();
        $a = $this->schedule($f, $f['courseA'], ['split_group_id' => $group, 'meeting_type' => 'lecture', 'meeting_index' => 1, 'status' => 'revision']);
        $b = $this->schedule($f, $f['courseA'], ['day' => 'Wednesday', 'split_group_id' => $group, 'meeting_type' => 'laboratory', 'meeting_index' => 2, 'status' => 'completed']);
        $this->historyFor($f, [$a, $b]);
        $before = $this->snapshot();
        $token = $this->preview($f, [$f['section']->id])->json('token');

        SchedulingAuditLog::creating(static function (SchedulingAuditLog $log): void {
            if ($log->action === 'schedule_reset') {
                throw new \RuntimeException('Simulated failure after deletion.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($f['user'])->postJson('/api/schedules/reset', [
                'semester_id' => $f['semester']->id, 'section_ids' => [$f['section']->id], 'expected_token' => $token,
            ]);
            $this->fail('The simulated failure should propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure after deletion.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted('schedules', ['id' => $a->id]);
        $this->assertNotSoftDeleted('schedules', ['id' => $b->id]);
        $this->assertSame(2, ScheduleSplit::query()->whereIn('schedule_id', [$a->id, $b->id])->count());
        $this->assertDatabaseHas('schedules', ['id' => $a->id, 'status' => 'revision']);
        $this->assertSame($before, $this->snapshot(), 'A rolled-back reset leaves history and submissions untouched.');
        $this->assertDatabaseMissing('system_notifications', ['type' => 'cross_department_instructors_released']);
    }

    public function test_cross_department_instructors_are_notified_only_after_a_committed_reset(): void
    {
        $f = $this->fixture();
        $teaching = Departments::create(['department_name' => 'Teaching Department', 'department_code' => 'TCH']);
        $teachingProgram = Program::create(['department_id' => $teaching->id, 'code' => 'TP', 'name' => 'Teaching Program']);
        $teachingSecretary = User::factory()->create(['role' => 'secretary', 'department_id' => $teaching->id, 'is_active' => true]);
        $f['courseA']->update(['teaching_department_id' => $teaching->id, 'teaching_program_id' => $teachingProgram->id]);
        $instructor = Faculty::create(['first_name' => 'Ana', 'last_name' => 'Cruz', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $teaching->id, 'program_id' => $teachingProgram->id, 'status' => 'active']);
        $this->schedule($f, $f['courseA'], ['faculty_id' => $instructor->id]);

        $preview = $this->preview($f, [$f['section']->id])->assertJsonPath('counts.cross_department_instructor_assignments', 1);
        $this->reset($f, [$f['section']->id], $preview->json('token'))->assertOk();

        $notice = DB::table('system_notifications')->where('user_id', $teachingSecretary->id)->where('type', 'cross_department_instructors_released')->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('reset its schedule', $notice->message);
        $this->assertDatabaseHas('faculties', ['id' => $instructor->id, 'deleted_at' => null]);
    }

    /** @return array<string, mixed> */
    private function fixture(string $suffix = ''): array
    {
        $department = Departments::create(['department_name' => 'Department A'.$suffix, 'department_code' => 'DA'.substr(md5($suffix), 0, 4)]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'PA'.substr(md5($suffix), 0, 4), 'name' => 'Program A']);
        $semester = Semester::query()->where('is_active', true)->first() ?? Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create(['room_code' => 'R'.substr(md5($suffix.'room'), 0, 6), 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $course = static fn (string $code): Course => Course::create([
            'course_code' => $code, 'course_name' => "Course {$code}", 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);
        $f = [
            'department' => $department,
            'program' => $program,
            'semester' => $semester,
            'room' => $room,
            'courseA' => $course('A1'.substr(md5($suffix), 0, 4)),
            'courseB' => $course('B1'.substr(md5($suffix), 0, 4)),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
            'faculty' => Faculty::create(['first_name' => 'Ben', 'last_name' => 'Reyes', 'employment_type' => 'full-time', 'max_units' => 21, 'department_id' => $department->id, 'program_id' => $program->id, 'status' => 'active']),
        ];
        $f['section'] = $this->section($f, 'A1');

        return $f;
    }

    private function section(array $f, string $name, ?Semester $semester = null): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $f['department']->id, 'program_id' => $f['program']->id,
            'semester_id' => ($semester ?? $f['semester'])->id, 'status' => 'active',
        ]);
    }

    private function schedule(array $f, Course $course, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $f['semester']->id, 'section_id' => $f['section']->id, 'course_id' => $course->id,
            'room_id' => $f['room']->id, 'department_id' => $f['department']->id, 'program_id' => $f['program']->id,
            'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => 'draft',
        ], $overrides));
    }

    /** @param list<Schedule> $schedules */
    private function historyFor(array $f, array $schedules): void
    {
        $version = ScheduleHistoryVersion::create([
            'semester_id' => $f['semester']->id, 'academic_year' => '2026-2027', 'semester' => '1st',
            'department_id' => $f['department']->id, 'action' => 'schedule_approved_by_vpaa', 'source' => 'department_workflow',
            'signatories' => ['prepared_by' => ['name' => 'Secretary']],
        ]);
        foreach ($schedules as $schedule) {
            ScheduleHistoryItem::create([
                'history_version_id' => $version->id, 'original_schedule_id' => $schedule->id,
                'after_snapshot' => $schedule->getAttributes(), 'snapshot_metadata' => ['section_name' => 'A1'],
            ]);
        }
        $submission = ScheduleSubmission::create([
            'department_id' => $f['department']->id, 'semester_id' => $f['semester']->id,
            'revision_number' => (int) ScheduleSubmission::query()->where('department_id', $f['department']->id)->max('revision_number') + 1,
            'status' => 'withdrawn', 'submitted_at' => now()->subDay(),
            'dean_reviewed_at' => now()->subDay(),
        ]);
        $submission->sections()->attach([$f['section']->id], ['state' => 'withdrawn']);
        SchedulingAuditLog::create([
            'semester_id' => $f['semester']->id, 'department_id' => $f['department']->id,
            'history_version_id' => $version->id, 'action' => 'schedule_approved_by_vpaa', 'created_at' => now()->subDay(),
        ]);
    }

    /** @param array<int, string> $sectionStates section id => pivot state */
    private function submission(array $f, string $status, array $sectionStates): ScheduleSubmission
    {
        $submission = ScheduleSubmission::create([
            'department_id' => $f['department']->id, 'semester_id' => $f['semester']->id,
            'revision_number' => (int) ScheduleSubmission::query()->where('department_id', $f['department']->id)->max('revision_number') + 1,
            'status' => $status, 'submitted_at' => now()->subHour(),
        ]);
        foreach ($sectionStates as $sectionId => $state) {
            $submission->sections()->attach([$sectionId], ['state' => $state]);
        }

        return $submission;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        return collect(self::PRESERVED_TABLES)
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])
            ->all();
    }

    private function preview(array $f, array $sectionIds, int $status = 200)
    {
        return $this->actingAs($f['user'])->postJson('/api/schedules/reset/preview', [
            'semester_id' => $f['semester']->id, 'section_ids' => $sectionIds,
        ])->assertStatus($status);
    }

    private function reset(array $f, array $sectionIds, ?string $token = null)
    {
        return $this->actingAs($f['user'])->postJson('/api/schedules/reset', [
            'semester_id' => $f['semester']->id,
            'section_ids' => $sectionIds,
            'expected_token' => $token ?? $this->preview($f, $sectionIds)->json('token'),
        ]);
    }
}
