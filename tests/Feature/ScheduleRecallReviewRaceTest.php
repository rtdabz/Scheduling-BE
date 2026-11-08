<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScheduleHistoryRecorder;
use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * A review, recall, or submission reads the department's workflow state before it writes. Another
 * workflow request can commit between that read and the write. These tests run that other request
 * at the moment the first one opens its transaction -- the window a real concurrent request lands
 * in -- and check that the first request acts on the current state rather than its earlier read.
 *
 * The interleaving is deterministic (a one-shot transaction listener), so these tests prove the
 * post-wait revalidation. They cannot prove row locking itself: SQLite ignores FOR UPDATE. The
 * MySQL/MariaDB check is documented in wicars-ui/docs/plan/schedule_recall_approval_fix_plan.md.
 */
class ScheduleRecallReviewRaceTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private Semester $semester;

    private User $secretary;

    private User $dean;

    private User $vpaa;

    /** @var array<string, Sections> */
    private array $sections = [];

    /** @var array<string, Schedule> */
    private array $meetings = [];

    /**
     * The old revision S1 was partially recalled earlier (B withdrawn), so its status stays
     * partially_withdrawn through a further recall of A. A reviewer opens S1 ({A, C}); before the
     * review writes, A is recalled and resubmitted as S2 (and, for VPAA, Dean-approved under S2).
     * The old review must not move A through S1.
     */
    public static function reviewActions(): array
    {
        return [
            'dean approval' => ['dean', 'approve', 'submitted'],
            'dean return' => ['dean', 'return', 'submitted'],
            'vpaa approval' => ['vpaa', 'approve', 'approved_by_dean'],
            'vpaa return' => ['vpaa', 'return', 'approved_by_dean'],
        ];
    }

    #[DataProvider('reviewActions')]
    public function test_an_old_review_cannot_cross_into_a_recalled_and_resubmitted_section(string $stage, string $action, string $meetingStatus): void
    {
        $this->fixture(['A' => $meetingStatus, 'B' => 'revision', 'C' => $meetingStatus]);
        $old = $this->submission(['A', 'C'], 'partially_withdrawn', withdrawn: ['B']);
        $reviewer = $stage === 'dean' ? $this->dean : $this->vpaa;

        $this->beforeNextTransaction(function () use ($stage, $reviewer): void {
            $this->recall(['A'])->assertOk();
            $this->submit()->assertOk();
            if ($stage === 'vpaa') {
                $this->review('dean', 'approve', $this->latestSubmission()->id)->assertOk();
            }
            $this->actingAs($reviewer);
        });

        $this->review($stage, $action, $old->id, $reviewer)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'submission_stage_changed');

        $new = $this->latestSubmission();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($stage === 'dean' ? 'pending_dean' : 'pending_vpaa', $new->refresh()->status);
        $this->assertSame($stage === 'dean' ? 'submitted' : 'approved_by_dean', $this->meetingStatus('A'));
        $this->assertSame($meetingStatus, $this->meetingStatus('C'));
        $this->assertSame('partially_withdrawn', $old->refresh()->status);
        $this->assertNull($stage === 'dean' ? $old->dean_reviewed_by : $old->vpaa_reviewed_by);
        $this->assertNull($old->rejection_reason);
        $this->assertSame(0, SchedulingAuditLog::query()
            ->where('schedule_submission_id', $old->id)
            ->where('action', 'like', '%_by_'.$stage)
            ->count());
    }

    /**
     * After the race is rejected, the remaining section of the old revision is still reviewable
     * through it, and the resubmitted section completes review through its new submission.
     */
    public function test_both_revisions_finish_normally_after_the_rejected_race(): void
    {
        $this->fixture(['A' => 'submitted', 'B' => 'revision', 'C' => 'submitted']);
        $old = $this->submission(['A', 'C'], 'partially_withdrawn', withdrawn: ['B']);
        $this->beforeNextTransaction(function (): void {
            $this->recall(['A'])->assertOk();
            $this->submit()->assertOk();
            $this->actingAs($this->dean);
        });
        $this->review('dean', 'approve', $old->id)->assertStatus(409);
        $new = $this->latestSubmission();

        $this->review('dean', 'approve', $old->id)->assertOk()->assertJsonPath('schedules_updated', 1);
        $this->assertSame('approved_by_dean', $this->meetingStatus('C'));
        $this->assertSame('submitted', $this->meetingStatus('A'));

        $this->review('dean', 'approve', $new->id)->assertOk()->assertJsonPath('schedules_updated', 2);
        $this->review('vpaa', 'approve', $new->id)->assertOk();
        $this->review('vpaa', 'approve', $old->id)->assertOk()->assertJsonPath('schedules_updated', 1);

        foreach (['A', 'B', 'C'] as $section) {
            $this->assertSame('faculty_assignment', $this->meetingStatus($section));
        }
        $this->assertSame('approved', $new->refresh()->status);
        $this->assertSame('partially_withdrawn', $old->refresh()->status);
    }

    /** A request that omits the submission ID stays on the revision it resolved; it never retargets the newer one. */
    public function test_a_review_without_a_submission_id_stays_on_the_revision_it_resolved(): void
    {
        $this->fixture(['A' => 'submitted', 'C' => 'submitted']);
        $old = $this->submission(['A', 'C'], 'pending_dean');
        $this->beforeNextTransaction(function (): void {
            $this->recall(['A'])->assertOk();
            $this->submit()->assertOk();
            $this->actingAs($this->dean);
        });

        $this->review('dean', 'approve', null)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'submission_stage_changed');

        $new = $this->latestSubmission();
        $this->assertSame('pending_dean', $new->status);
        $this->assertSame('submitted', $this->meetingStatus('A'));
        $this->assertSame('submitted', $this->meetingStatus('C'));
        $this->assertSame('partially_withdrawn', $old->refresh()->status);
        $this->assertNull($new->dean_reviewed_by);
    }

    #[DataProvider('reviewActions')]
    public function test_a_full_recall_before_the_review_is_refused_and_leaves_meetings_in_revision(string $stage, string $action, string $meetingStatus): void
    {
        $this->fixture(['A' => $meetingStatus]);
        $submission = $this->submission(['A'], $stage === 'dean' ? 'pending_dean' : 'pending_vpaa');
        $this->recall(['A'])->assertOk();

        $this->review($stage, $action, $submission->id)->assertStatus(422);

        $this->assertSame('revision', $this->meetingStatus('A'));
        $this->assertSame('withdrawn', $submission->refresh()->status);
    }

    #[DataProvider('reviewActions')]
    public function test_a_full_recall_during_the_review_is_refused_as_a_changed_stage(string $stage, string $action, string $meetingStatus): void
    {
        $this->fixture(['A' => $meetingStatus]);
        $submission = $this->submission(['A'], $stage === 'dean' ? 'pending_dean' : 'pending_vpaa');
        $reviewer = $stage === 'dean' ? $this->dean : $this->vpaa;
        $this->beforeNextTransaction(function () use ($reviewer): void {
            $this->recall(['A'])->assertOk();
            $this->actingAs($reviewer);
        });

        $this->review($stage, $action, $submission->id, $reviewer)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'submission_stage_changed');

        $this->assertSame('revision', $this->meetingStatus('A'));
        $this->assertSame('withdrawn', $submission->refresh()->status);
        $this->assertNull($stage === 'dean' ? $submission->dean_reviewed_by : $submission->vpaa_reviewed_by);
    }

    public function test_a_competing_decision_loses_without_stamping_a_reviewer(): void
    {
        $this->fixture(['A' => 'submitted']);
        $submission = $this->submission(['A'], 'pending_dean');
        // A department has one Dean; the VPAA may complete the Dean step, so the two decide in competition.
        $otherReviewer = $this->vpaa;
        $this->beforeNextTransaction(function () use ($otherReviewer): void {
            $this->review('dean', 'approve', $this->latestSubmission()->id, $otherReviewer)->assertOk();
            $this->actingAs($this->dean);
        });

        $this->review('dean', 'return', $submission->id)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'submission_stage_changed');

        $submission->refresh();
        $this->assertSame('approved_by_dean', $this->meetingStatus('A'));
        $this->assertSame('pending_vpaa', $submission->status);
        $this->assertSame($otherReviewer->id, $submission->dean_reviewed_by);
        $this->assertNull($submission->rejection_reason);
        $this->assertSame(1, SchedulingAuditLog::query()->where('action', 'like', 'schedule_%_by_dean')->count());
    }

    public function test_a_repeated_approval_is_refused(): void
    {
        $this->fixture(['A' => 'submitted']);
        $submission = $this->submission(['A'], 'pending_dean');
        $this->beforeNextTransaction(function () use ($submission): void {
            $this->review('dean', 'approve', $submission->id)->assertOk();
        });

        $this->review('dean', 'approve', $submission->id)->assertStatus(409);

        $this->assertSame(1, SchedulingAuditLog::query()->where('action', 'schedule_approved_by_dean')->count());
        $this->assertSame(1, ScheduleHistoryVersion::query()->where('action', 'schedule_approved_by_dean')->count());
    }

    public function test_a_duplicate_submission_creates_one_revision(): void
    {
        $this->fixture(['A' => 'completed', 'C' => 'completed']);
        $this->beforeNextTransaction(function (): void {
            $this->submit()->assertOk();
        });

        $this->submit()
            ->assertStatus(422)
            ->assertJsonPath('message', 'No plotted schedule sections are ready for submission.');

        $this->assertSame(1, ScheduleSubmission::query()->count());
        $this->assertSame(2, DB::table('schedule_submission_sections')->count());
        $this->assertSame(1, SchedulingAuditLog::query()->where('action', 'schedule_submitted')->count());
    }

    /** A recall that resolved A to S1 must not recall A's meetings after they moved to a newer S2. */
    public function test_a_stale_recall_does_not_recall_a_newer_revision(): void
    {
        $this->fixture(['A' => 'submitted', 'C' => 'submitted']);
        $old = $this->submission(['A', 'C'], 'pending_dean');
        $this->beforeNextTransaction(function (): void {
            $this->recall(['A'])->assertOk();
            $this->submit()->assertOk();
        });

        $this->recall(['A'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'submission_stage_changed');

        $new = $this->latestSubmission();
        $this->assertSame('pending_dean', $new->refresh()->status);
        $this->assertSame('submitted', $this->meetingStatus('A'));
        $this->assertSame('included', $this->pivotState($new, 'A'));
        $this->assertSame('partially_withdrawn', $old->refresh()->status);
        $this->assertSame(1, SchedulingAuditLog::query()->where('action', 'schedule_withdrawn')->count());
    }

    public function test_a_recall_with_one_ineligible_section_changes_nothing(): void
    {
        $this->fixture(['A' => 'submitted', 'C' => 'approved_by_dean', 'D' => 'draft']);
        $first = $this->submission(['A'], 'pending_dean');
        $second = $this->submission(['C'], 'pending_vpaa');

        $this->recall(['A', 'C', 'D'])->assertStatus(422);

        $this->assertSame('submitted', $this->meetingStatus('A'));
        $this->assertSame('approved_by_dean', $this->meetingStatus('C'));
        $this->assertSame('pending_dean', $first->refresh()->status);
        $this->assertSame('pending_vpaa', $second->refresh()->status);
    }

    public function test_a_recall_that_waits_for_a_review_recalls_the_resulting_stage(): void
    {
        $this->fixture(['A' => 'submitted', 'C' => 'submitted']);
        $submission = $this->submission(['A', 'C'], 'pending_dean');
        $this->beforeNextTransaction(function () use ($submission): void {
            $this->review('dean', 'approve', $submission->id)->assertOk();
            $this->actingAs($this->secretary);
        });

        $this->recall(['A'])->assertOk()->assertJsonPath('withdrawal_stage', 'vpaa_review');

        $this->assertSame('revision', $this->meetingStatus('A'));
        $this->assertSame('approved_by_dean', $this->meetingStatus('C'));
        $this->assertSame('partially_withdrawn', $submission->refresh()->status);
        $this->assertSame($this->dean->id, $submission->dean_reviewed_by);
    }

    /** The reviewer whose queue loses sections is told which ones and how many remain for their decision. */
    #[DataProvider('recallNotices')]
    public function test_a_recall_during_review_tells_the_reviewers_what_left_and_what_remains(string $meetingStatus, string $submissionStatus, string $reviewer): void
    {
        $this->fixture(['A' => $meetingStatus, 'B' => $meetingStatus, 'C' => $meetingStatus]);
        $this->submission(['A', 'B', 'C'], $submissionStatus);

        $this->recall(['A'])->assertOk();

        foreach ([$this->dean, $this->vpaa] as $recipient) {
            $notice = DB::table('system_notifications')->where('user_id', $recipient->id)->where('type', 'schedule_withdrawn')->first();
            $this->assertNotNull($notice);
            $this->assertSame("Sections recalled from {$reviewer} review", $notice->title);
            $this->assertStringContainsString('Recalled: BSIT 1A.', $notice->message);
            $this->assertStringContainsString("2 of 3 sections remain for {$reviewer} approval.", $notice->message);
            $metadata = json_decode($notice->metadata, true);
            $this->assertSame(['BSIT 1A'], $metadata['recalled_section_names']);
            $this->assertSame(3, $metadata['original_section_count']);
            $this->assertSame(2, $metadata['remaining_section_count']);
        }
    }

    public static function recallNotices(): array
    {
        return [
            'from dean review' => ['submitted', 'pending_dean', 'Dean'],
            'from vpaa review' => ['approved_by_dean', 'pending_vpaa', 'VPAA'],
        ];
    }

    public function test_recalling_every_section_says_none_remain_and_an_approved_recall_keeps_its_title(): void
    {
        $this->fixture(['A' => 'submitted', 'B' => 'faculty_assignment']);
        $this->submission(['A'], 'pending_dean');
        $this->submission(['B'], 'approved');

        $this->recall(['A'])->assertOk();
        $this->recall(['B'])->assertOk();

        $notices = DB::table('system_notifications')->where('user_id', $this->dean->id)->where('type', 'schedule_withdrawn')->orderBy('id')->get();
        $this->assertSame('Sections recalled from Dean review', $notices[0]->title);
        $this->assertStringContainsString('No sections remain for approval.', $notices[0]->message);
        $this->assertSame('Schedule submission recalled', $notices[1]->title);
        $this->assertStringContainsString('Recalled: BSIT 1B.', $notices[1]->message);
        $this->assertStringNotContainsString('remain', $notices[1]->message);
    }

    public function test_a_failure_while_recording_history_rolls_the_whole_decision_back(): void
    {
        $this->fixture(['A' => 'submitted']);
        $submission = $this->submission(['A'], 'pending_dean');
        $this->app->instance(ScheduleHistoryRecorder::class, new class extends ScheduleHistoryRecorder
        {
            public function __construct() {}

            public function record(...$arguments): ScheduleHistoryVersion
            {
                throw new RuntimeException('History store unavailable.');
            }
        });

        $this->review('dean', 'approve', $submission->id)->assertStatus(500);

        $submission->refresh();
        $this->assertSame('submitted', $this->meetingStatus('A'));
        $this->assertSame('pending_dean', $submission->status);
        $this->assertNull($submission->dean_reviewed_by);
        $this->assertSame(0, SchedulingAuditLog::query()->count());
        $this->assertDatabaseMissing('system_notifications', ['type' => 'schedule_approved_by_dean']);
    }

    /**
     * Run $callback once, when the next database transaction opens. The listener is removed by the
     * one-shot flag before the callback runs, so the callback's own transactions do not re-enter it.
     */
    private function beforeNextTransaction(Closure $callback): void
    {
        $pending = true;
        $this->app['events']->listen(TransactionBeginning::class, function () use (&$pending, $callback): void {
            if (! $pending) {
                return;
            }
            $pending = false;
            $callback();
        });
    }

    private function review(string $stage, string $action, ?int $submissionId, ?User $reviewer = null)
    {
        $reviewer ??= $stage === 'dean' ? $this->dean : $this->vpaa;
        $payload = array_filter([
            'schedule_submission_id' => $submissionId,
            'rejection_reason' => $action === 'return' ? 'Revise the package.' : null,
        ], static fn ($value): bool => $value !== null);

        return $this->actingAs($reviewer)->postJson(
            "/api/departments/{$this->department->id}/{$action}-by-{$stage}",
            $payload,
        );
    }

    private function recall(array $sections)
    {
        return $this->actingAs($this->secretary)->postJson(
            "/api/departments/{$this->department->id}/withdraw-submission",
            ['section_ids' => array_map(fn (string $key): int => $this->sections[$key]->id, $sections)],
        );
    }

    private function submit()
    {
        return $this->actingAs($this->secretary)->postJson("/api/departments/{$this->department->id}/submit-schedules");
    }

    private function meetingStatus(string $section): string
    {
        return $this->meetings[$section]->refresh()->status;
    }

    private function latestSubmission(): ScheduleSubmission
    {
        return ScheduleSubmission::query()->latest('revision_number')->firstOrFail();
    }

    private function pivotState(ScheduleSubmission $submission, string $section): ?string
    {
        return DB::table('schedule_submission_sections')
            ->where('schedule_submission_id', $submission->id)
            ->where('section_id', $this->sections[$section]->id)
            ->value('state');
    }

    /** @param  array<string, string>  $meetingStatuses  section key => status of its single meeting */
    private function fixture(array $meetingStatuses): void
    {
        $this->department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'CIT']);
        $program = Program::create(['department_id' => $this->department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create(['room_code' => 'CIT 101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $this->department->id]);
        $course = Course::create([
            'course_code' => 'IT 101', 'course_name' => 'Programming', 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'status' => 'active',
        ]);
        $this->secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]));
        $this->dean = User::factory()->create(['role' => 'dean', 'department_id' => $this->department->id]);
        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);

        // One room, so each section meets on its own day to stay conflict-free.
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday'];
        foreach (array_keys($meetingStatuses) as $index => $key) {
            $this->sections[$key] = Sections::create([
                'section_name' => "BSIT 1{$key}", 'year_level' => '1', 'semester' => '1st',
                'department_id' => $this->department->id, 'program_id' => $program->id,
                'semester_id' => $this->semester->id, 'status' => 'active',
            ]);
            $this->meetings[$key] = Schedule::create([
                'semester_id' => $this->semester->id, 'section_id' => $this->sections[$key]->id, 'course_id' => $course->id,
                'room_id' => $room->id, 'department_id' => $this->department->id, 'day' => $days[$index],
                'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => $meetingStatuses[$key],
            ]);
        }
    }

    /**
     * @param  list<string>  $included
     * @param  list<string>  $withdrawn
     */
    private function submission(array $included, string $status, array $withdrawn = []): ScheduleSubmission
    {
        $submission = ScheduleSubmission::create([
            'department_id' => $this->department->id,
            'semester_id' => $this->semester->id,
            'revision_number' => ((int) ScheduleSubmission::query()->max('revision_number')) + 1,
            'status' => $status,
            'submitted_at' => now(),
        ]);
        foreach ($included as $key) {
            $submission->sections()->attach($this->sections[$key]->id, ['state' => 'included']);
        }
        foreach ($withdrawn as $key) {
            $submission->sections()->attach($this->sections[$key]->id, ['state' => 'withdrawn']);
        }

        return $submission;
    }
}
