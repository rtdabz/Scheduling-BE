<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\InstitutionSetting;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\ScheduleSignatories;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The signature block of a submitted schedule names the officials who prepared, reviewed, recommended,
 * and approved it. Replacing an official or editing their account must not rewrite past schedules.
 */
class ScheduleSignatoryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private Semester $semester;

    private Sections $section;

    private User $programHead;

    private User $dean;

    private User $vpaa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'CIT']);
        $program = Program::create(['department_id' => $this->department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create(['room_code' => 'CIT 101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $this->department->id]);
        $course = Course::create([
            'course_code' => 'IT 101', 'course_name' => 'Programming', 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'status' => 'active',
        ]);
        $this->section = Sections::create([
            'section_name' => 'BSIT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $this->department->id,
            'program_id' => $program->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]);
        $this->programHead = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head', 'name' => 'Paula Head', 'department_id' => $this->department->id, 'program_id' => $program->id,
        ]));
        $this->dean = User::factory()->create(['role' => 'dean', 'name' => 'Diego Dean', 'department_id' => $this->department->id]);
        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'name' => 'Vera Vpaa', 'department_id' => null]);
        InstitutionSetting::current()->update(['president_name' => 'Olivia President', 'president_title' => 'OIC-College President']);

        $this->actingAs($this->programHead);
        Schedule::create([
            'semester_id' => $this->semester->id, 'section_id' => $this->section->id, 'course_id' => $course->id, 'room_id' => $room->id,
            'department_id' => $this->department->id, 'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00',
            'mode' => 'on-site', 'status' => 'completed',
        ]);
    }

    public function test_an_approved_schedule_keeps_its_officials_after_they_are_replaced_or_renamed(): void
    {
        $submissionId = $this->submit();
        $this->actingAs($this->dean)->postJson($this->url('approve-by-dean'), ['schedule_submission_id' => $submissionId])->assertOk();
        $this->actingAs($this->vpaa)->postJson($this->url('approve-by-vpaa'), ['schedule_submission_id' => $submissionId])->assertOk();

        $this->replaceAllOfficials();

        $expected = ['Paula Head', 'Diego Dean', 'Vera Vpaa', 'Olivia President'];
        $this->assertSame($expected, $this->names(ScheduleSubmission::find($submissionId)->signatories));
        $this->assertSame('OIC-College President', ScheduleSubmission::find($submissionId)->signatories['approved']['title']);

        $payload = $this->actingAs($this->newVpaa())->getJson('/api/initial-data?approval_queue=1')->assertOk();
        $this->assertSame($expected, $this->names($payload->json("schedule_signatories.{$submissionId}")));
        $schedule = collect($payload->json('schedules'))->firstWhere('section_id', $this->section->id);
        $this->assertSame($submissionId, $schedule['signatories_submission_id']);

        $history = collect($this->getJson('/api/schedule-history?type=approved')->assertOk()->json('data'));
        // Schedule History lists only the final (VPAA) approval; the Dean's review is recorded on it.
        $this->assertCount(1, $history);
        $vpaaStep = $history->firstWhere('action', 'schedule_approved_by_vpaa');
        $this->assertSame($expected, $this->names($vpaaStep['signatories']));
        $this->assertSame('Diego Dean', $vpaaStep['signatories']['reviewed']['name']);
    }

    public function test_reports_and_load_sheets_print_the_recorded_officials_after_they_change(): void
    {
        $submissionId = $this->submit();
        $this->actingAs($this->dean)->postJson($this->url('approve-by-dean'), ['schedule_submission_id' => $submissionId])->assertOk();
        $this->actingAs($this->vpaa)->postJson($this->url('approve-by-vpaa'), ['schedule_submission_id' => $submissionId])->assertOk();
        $faculty = Faculty::create(['first_name' => 'Assigned', 'last_name' => 'Instructor', 'department_id' => $this->department->id,
            'employment_type' => 'full-time', 'status' => 'active']);
        Schedule::query()->update(['faculty_id' => $faculty->id, 'faculty_assignment_done' => true, 'status' => 'finalized']);

        $this->replaceAllOfficials();
        $expected = ['Paula Head', 'Diego Dean', 'Vera Vpaa', 'Olivia President'];

        $report = $this->actingAs($this->newVpaa())->getJson("/api/reports/departments/{$this->department->id}")->assertOk();
        $this->assertSame($submissionId, $report->json('schedules.0.signatories_submission_id'));
        $this->assertSame($expected, $this->names($report->json("schedule_signatories.{$submissionId}")));

        $rows = $this->getJson("/api/schedules?faculty_id={$faculty->id}&semester_id=active&with_signatories=1")->assertOk();
        $this->assertSame($expected, $this->names($rows->json('0.signatories')));
        $this->assertArrayNotHasKey('signatories', $this->getJson("/api/schedules?faculty_id={$faculty->id}")->json('0'));
        $this->assertSame($expected, $this->names(ScheduleSubmission::find($submissionId)->signatories));
    }

    public function test_reports_leave_unsubmitted_rows_unsigned(): void
    {
        $report = $this->actingAs($this->vpaa)->getJson("/api/reports/departments/{$this->department->id}")->assertOk();
        $this->assertNull(collect($report->json('schedules'))->firstWhere('signatories_submission_id', '!=', null));
        $this->assertSame([], (array) $report->json('schedule_signatories'));
    }

    public function test_a_returned_schedule_keeps_the_dean_who_returned_it(): void
    {
        $submissionId = $this->submit();
        $this->actingAs($this->dean)->postJson($this->url('return-by-dean'), [
            'schedule_submission_id' => $submissionId, 'rejection_reason' => 'Fix Monday.',
        ])->assertOk();

        $this->replaceAllOfficials();

        $this->assertSame(['Paula Head', 'Diego Dean', 'Vera Vpaa', 'Olivia President'], $this->names(ScheduleSubmission::find($submissionId)->signatories));
        $version = ScheduleHistoryVersion::query()->where('action', 'schedule_returned_by_dean')->sole();
        $this->assertSame('Diego Dean', $version->signatories['reviewed']['name']);
        $payload = $this->actingAs($this->programHead)->getJson('/api/initial-data')->assertOk();
        $schedule = collect($payload->json('schedules'))->firstWhere('section_id', $this->section->id);
        $this->assertSame($submissionId, $schedule['signatories_submission_id']);
        $this->assertSame('Diego Dean', $payload->json("schedule_signatories.{$submissionId}.reviewed.name"));
    }

    public function test_a_finalized_semester_snapshot_preserves_the_original_signatories_after_officials_change(): void
    {
        $submissionId = $this->submit();
        $this->actingAs($this->dean)->postJson($this->url('approve-by-dean'), ['schedule_submission_id' => $submissionId])->assertOk();
        $this->actingAs($this->vpaa)->postJson($this->url('approve-by-vpaa'), ['schedule_submission_id' => $submissionId])->assertOk();
        $recorded = ScheduleSubmission::findOrFail($submissionId)->signatories;
        $faculty = Faculty::create(['first_name' => 'Assigned', 'last_name' => 'Instructor', 'department_id' => $this->department->id,
            'employment_type' => 'full-time', 'status' => 'active']);
        Schedule::query()->update(['faculty_id' => $faculty->id, 'faculty_assignment_done' => true, 'status' => 'finalized']);
        $this->replaceAllOfficials();
        $next = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);

        $this->actingAs($this->newVpaa())->patchJson('/api/semesters/'.$next->id.'/activate')->assertOk();

        $listed = $this->getJson('/api/schedule-history?type=archived&semester_id='.$this->semester->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.signatories', null);
        $response = $this->getJson('/api/schedule-history/'.$listed->json('data.0.id'))->assertOk()
            ->assertJsonPath('data.signatories', null)
            ->assertJsonPath('data.snapshots.0.snapshot.faculty_id', $faculty->id)
            ->assertJsonPath('data.snapshots.0.snapshot.status', 'finalized');
        $this->assertSame($recorded, $response->json('data.snapshots.0.snapshot.signatories'));
        $this->assertSame(['Paula Head', 'Diego Dean', 'Vera Vpaa', 'Olivia President'], $this->names($response->json('data.snapshots.0.snapshot.signatories')));
        $this->assertSame($recorded, ScheduleSubmission::findOrFail($submissionId)->signatories);
    }

    public function test_semester_snapshots_keep_each_sections_latest_included_signatories_without_guessing_missing_names(): void
    {
        $second = $this->section->replicate()->fill(['section_name' => 'BSIT 1B']);
        $second->save();
        $legacy = $this->section->replicate()->fill(['section_name' => 'BSIT 1C']);
        $legacy->save();
        $original = Schedule::query()->sole();
        foreach ([$second, $legacy] as $section) {
            $original->replicate()->fill(['section_id' => $section->id])->save();
        }
        Schedule::query()->update(['status' => 'approved']);
        $old = ['prepared' => [], 'reviewed' => ['name' => 'Original Dean', 'title' => 'Dean'],
            'recommended' => null, 'approved' => null, 'frozen' => true];
        $new = [...$old, 'reviewed' => ['name' => 'Successor Dean', 'title' => 'Dean']];
        $firstSubmission = ScheduleSubmission::create(['department_id' => $this->department->id, 'semester_id' => $this->semester->id,
            'revision_number' => 1, 'status' => 'partially_withdrawn', 'signatories' => $old]);
        $firstSubmission->sections()->attach([$this->section->id => ['state' => 'included'], $second->id => ['state' => 'withdrawn'], $legacy->id => ['state' => 'included']]);
        $secondSubmission = ScheduleSubmission::create(['department_id' => $this->department->id, 'semester_id' => $this->semester->id,
            'revision_number' => 2, 'status' => 'approved', 'signatories' => $new]);
        $secondSubmission->sections()->attach([$second->id => ['state' => 'included'], $this->section->id => ['state' => 'withdrawn']]);
        $legacySubmission = ScheduleSubmission::create(['department_id' => $this->department->id, 'semester_id' => $this->semester->id,
            'revision_number' => 3, 'status' => 'approved', 'signatories' => null]);
        $legacySubmission->sections()->attach($legacy->id, ['state' => 'included']);
        $next = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);

        $this->actingAs($this->vpaa)->patchJson('/api/semesters/'.$next->id.'/activate')->assertOk();

        $archivedId = $this->getJson('/api/schedule-history?type=archived')->assertOk()->json('data.0.id');
        $snapshots = collect($this->getJson('/api/schedule-history/'.$archivedId)->assertOk()->json('data.snapshots'))->keyBy('section_id');
        $this->assertSame($old, $snapshots[$this->section->id]['snapshot']['signatories']);
        $this->assertSame($new, $snapshots[$second->id]['snapshot']['signatories']);
        $this->assertNull($snapshots[$legacy->id]['snapshot']['signatories']);
    }

    public function test_an_official_replaced_before_acting_is_succeeded_by_the_new_one(): void
    {
        $submissionId = $this->submit();
        $this->dean->update(['is_active' => false]);
        $newDean = User::factory()->create(['role' => 'dean', 'name' => 'Nadia Dean', 'department_id' => $this->department->id]);

        $payload = $this->actingAs($newDean)->getJson('/api/initial-data?approval_queue=1')->assertOk();
        $this->assertSame('Nadia Dean', $payload->json("schedule_signatories.{$submissionId}.reviewed.name"));

        $this->postJson($this->url('approve-by-dean'), ['schedule_submission_id' => $submissionId])->assertOk();
        $newDean->update(['name' => 'Nadia Renamed']);

        $this->assertSame('Nadia Dean', ScheduleSubmission::find($submissionId)->signatories['reviewed']['name']);
    }

    public function test_a_recalled_and_resubmitted_schedule_records_each_revision_separately(): void
    {
        $firstId = $this->submit();
        $this->actingAs($this->dean)->postJson($this->url('approve-by-dean'), ['schedule_submission_id' => $firstId])->assertOk();
        $this->actingAs($this->grantCapabilities($this->programHead))
            ->postJson($this->url('withdraw-submission'), ['section_ids' => [$this->section->id]])->assertOk();

        $this->replaceAllOfficials();
        $this->programHead->update(['name' => 'Paula Married']);
        $secondId = $this->submit();

        $first = ScheduleSubmission::find($firstId);
        $this->assertSame('withdrawn', $first->status);
        $this->assertSame(['Paula Head', 'Diego Dean', 'Vera Vpaa', 'Olivia President'], $this->names($first->signatories));
        $recall = ScheduleHistoryVersion::query()->where('action', 'schedule_withdrawn')->sole();
        $this->assertSame('Diego Dean', $recall->signatories['reviewed']['name']);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(['Paula Married', 'Nadia Dean', 'Nico Vpaa', 'Omar President'], $this->names(ScheduleSubmission::find($secondId)->signatories));
    }

    public function test_closed_legacy_submissions_never_guess_missing_historical_signatories(): void
    {
        $submission = ScheduleSubmission::findOrFail($this->submit());
        $service = app(ScheduleSignatories::class);
        $template = $submission;
        foreach (['approved', 'rejected_by_dean', 'rejected_by_vpaa', 'withdrawn', 'partially_withdrawn'] as $index => $status) {
            $submission = $template->replicate()->forceFill(['revision_number' => 100 + $index, 'status' => $status, 'vpaa_reviewed_at' => $status === 'partially_withdrawn' ? now() : null, 'signatories' => null]);
            $submission->save();
            $before = $service->resolve($submission->fresh());
            $this->assertSame(['', null, null, null], $this->names($before));
            $this->assertTrue($before['frozen']);

            InstitutionSetting::current()->update(['president_name' => 'New President', 'president_title' => 'New Designation']);
            $this->assertSame($before, $service->resolve($submission->fresh()));
            $this->assertNull($submission->fresh()->signatories);
        }
    }

    public function test_pending_documents_follow_the_current_president_without_rewriting_history(): void
    {
        $submission = ScheduleSubmission::findOrFail($this->submit());
        $stored = $submission->signatories;
        InstitutionSetting::current()->update(['president_name' => 'New President', 'president_title' => 'New Designation']);

        $resolved = app(ScheduleSignatories::class)->resolve($submission);
        $this->assertSame('New President', $resolved['approved']['name']);
        $this->assertSame('New Designation', $resolved['approved']['title']);
        $this->assertSame($stored, $submission->fresh()->signatories);

        $submission = $submission->replicate()->forceFill(['revision_number' => 100, 'signatories' => null]);
        $submission->save();
        $this->assertSame('New President', app(ScheduleSignatories::class)->resolve($submission->fresh())['approved']['name']);

        $submission->update(['status' => 'partially_withdrawn']);
        $this->assertSame('New President', app(ScheduleSignatories::class)->resolve($submission->fresh())['approved']['name']);
    }

    public function test_delegated_review_of_a_vacant_dean_slot_never_stamps_the_inactive_predecessor(): void
    {
        $submissionId = $this->submit();
        $this->dean->update(['is_active' => false]);
        $this->actingAs($this->vpaa)->postJson($this->url('approve-by-dean'), ['schedule_submission_id' => $submissionId])->assertOk();
        $recorded = ScheduleSubmission::findOrFail($submissionId)->signatories;
        $this->assertSame('Not recorded', $recorded['reviewed']['name']);
        $this->assertNull($recorded['reviewed']['user_id']);
        $this->assertSame($this->vpaa->id, $recorded['reviewed']['action_actor']['id']);
        User::factory()->create(['role' => 'dean', 'name' => 'Replacement Dean', 'department_id' => $this->department->id]);
        $this->assertSame($recorded['reviewed'], app(ScheduleSignatories::class)->stored(ScheduleSubmission::findOrFail($submissionId))['reviewed']);
    }

    public function test_completed_dean_slot_cannot_be_changed_while_awaiting_vpaa(): void
    {
        $id = $this->submit();
        $this->actingAs($this->dean)->postJson($this->url('approve-by-dean'), ['schedule_submission_id' => $id])->assertOk();
        $recorded = ScheduleSubmission::findOrFail($id)->signatories;
        $this->assertFalse($recorded['frozen']);
        $altered = $recorded;
        $altered['reviewed']['name'] = 'Replacement Actor';
        try {
            DB::table('schedule_submissions')->where('id', $id)->update(['signatories' => json_encode($altered)]);
            $this->fail('An already completed approval slot was replaced.');
        } catch (QueryException) {
            $this->assertSame($recorded, ScheduleSubmission::findOrFail($id)->signatories);
        }
    }

    private function submit(): int
    {
        return (int) $this->actingAs($this->programHead)
            ->postJson($this->url('submit-schedules'))
            ->assertOk()
            ->json('schedule_submission_id');
    }

    private function url(string $action): string
    {
        return "/api/departments/{$this->department->id}/{$action}";
    }

    /** Rename the preparer, replace the Dean and VPAA, and change the President setting. */
    private function replaceAllOfficials(): void
    {
        $this->programHead->update(['name' => 'Paula Renamed']);
        $this->dean->update(['name' => 'Diego Renamed', 'is_active' => false]);
        User::factory()->create(['role' => 'dean', 'name' => 'Nadia Dean', 'department_id' => $this->department->id]);
        $this->vpaa->update(['name' => 'Vera Renamed', 'is_active' => false]);
        $this->newVpaa();
        InstitutionSetting::current()->update(['president_name' => 'Omar President', 'president_title' => 'College President']);
    }

    private function newVpaa(): User
    {
        return User::query()->where('name', 'Nico Vpaa')->first()
            ?? User::factory()->create(['role' => 'vpaa', 'name' => 'Nico Vpaa', 'department_id' => null]);
    }

    private function names(?array $signatories): array
    {
        return [
            collect($signatories['prepared'] ?? [])->pluck('name')->implode(' / '),
            $signatories['reviewed']['name'] ?? null,
            $signatories['recommended']['name'] ?? null,
            $signatories['approved']['name'] ?? null,
        ];
    }
}
