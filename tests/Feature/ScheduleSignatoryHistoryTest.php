<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\InstitutionSetting;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertCount(2, $history);
        $deanStep = $history->firstWhere('action', 'schedule_approved_by_dean');
        $vpaaStep = $history->firstWhere('action', 'schedule_approved_by_vpaa');
        $this->assertSame($expected, $this->names($vpaaStep['signatories']));
        $this->assertSame('Diego Dean', $deanStep['signatories']['reviewed']['name']);
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
