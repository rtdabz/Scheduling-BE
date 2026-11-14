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
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScheduleSemesterArchiver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SemesterSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Semester $first;

    private Semester $second;

    private Semester $summer;

    private User $vpaa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->first = Semester::create(['semester' => '1st', 'academic_year' => '2026-2027', 'is_active' => true]);
        $this->second = Semester::create(['semester' => '2nd', 'academic_year' => '2026-2027', 'is_active' => false]);
        $this->summer = Semester::create(['semester' => 'summer', 'academic_year' => '2026-2027', 'is_active' => false, 'is_enabled' => false]);
        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'name' => 'Recorded VPAA']);
        $this->actingAs($this->vpaa);
    }

    public function test_activation_preserves_every_workflow_status_and_all_existing_history_in_both_periods(): void
    {
        $department = Departments::create(['department_name' => 'Computing', 'department_code' => 'CC']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'IT']);
        $faculty = Faculty::create(['first_name' => 'Original', 'last_name' => 'Instructor', 'department_id' => $department->id, 'employment_type' => 'full-time', 'status' => 'active']);
        $room = Rooms::create(['room_code' => 'CC101', 'room_type' => 'lecture', 'department_id' => $department->id, 'status' => 'available']);
        $course = Course::create(['course_code' => 'IT101', 'course_name' => 'Computing', 'department_id' => $department->id,
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major',
            'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
        foreach ([$this->first, $this->second] as $semester) {
            $section = Sections::create(['section_name' => 'IT1A', 'year_level' => '1', 'semester' => $semester->semester,
                'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']);
            foreach (SchedulingPolicy::SCHEDULE_STATUSES as $status) {
                Schedule::create(['semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $course->id,
                    'department_id' => $department->id, 'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00',
                    'mode' => 'on-site', 'room_id' => $room->id, 'faculty_id' => $faculty->id, 'faculty_assignment_done' => true,
                    'split_group_id' => '114f1a2f-41b9-4f12-ab2a-fec1517cb67a', 'meeting_type' => 'lecture', 'meeting_index' => 1,
                    'status' => $status]);
            }
            $submission = ScheduleSubmission::create(['department_id' => $department->id, 'semester_id' => $semester->id,
                'revision_number' => 1, 'status' => 'recalled', 'submitted_by' => $this->vpaa->id]);
            DB::table('schedule_submission_sections')->insert(['schedule_submission_id' => $submission->id, 'section_id' => $section->id]);
            $history = ScheduleHistoryVersion::create(['semester_id' => $semester->id, 'academic_year' => $semester->academic_year,
                'semester' => $semester->semester, 'department_id' => $department->id, 'action' => 'schedule_approved_by_vpaa',
                'signatories' => ['president' => ['name' => 'Previous President', 'designation' => 'President']]]);
            ScheduleHistoryItem::create(['history_version_id' => $history->id, 'after_snapshot' => ['status' => 'approved']]);
        }
        $tables = ['sections', 'schedules', 'schedule_splits', 'schedule_submissions', 'schedule_submission_sections', 'schedule_history_versions', 'schedule_history_items'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()]);

        Event::listen('eloquent.creating: '.ScheduleHistoryItem::class, fn () => throw new \RuntimeException('Injected snapshot failure'));
        $this->activate($this->second)->assertStatus(500);
        Event::forget('eloquent.creating: '.ScheduleHistoryItem::class);
        $this->assertTrue((bool) $this->first->fresh()->is_active);
        $this->assertFalse((bool) $this->second->fresh()->is_active);
        $this->assertSame(2, ScheduleHistoryVersion::count());
        $this->assertSame(0, SchedulingAuditLog::count());

        $response = $this->activate($this->second)->assertOk()->assertJsonCount(1, 'history_version_ids')->assertJsonPath('reset_section_count', 0);

        foreach ($tables as $table) {
            $query = DB::table($table)->orderBy('id');
            if (in_array($table, ['schedule_history_versions', 'schedule_history_items'], true)) {
                $query->whereIn('id', collect($before[$table])->pluck('id'));
            }
            $this->assertEquals($before[$table], $query->get()->toArray(), $table.' changed during activation');
        }
        $version = ScheduleHistoryVersion::findOrFail($response->json('history_version_ids.0'));
        $this->assertSame($this->first->id, $version->semester_id);
        $this->assertEqualsCanonicalizing(ScheduleSemesterArchiver::VPAA_APPROVED_STATUSES, $version->items->pluck('after_snapshot.status')->all());
        $this->assertSame(4, $version->items->count());
        $this->assertSame(3, ScheduleHistoryVersion::count());
        $this->assertSame(6, ScheduleHistoryItem::count());
        $this->assertSame([$version->id], SchedulingAuditLog::where('action', 'semester_ended')->first()->metadata['history_version_ids']);

        $faculty->update(['first_name' => 'Renamed']);
        $room->update(['room_code' => 'RENAMED']);
        $course->update(['course_code' => 'RENAMED']);
        $history = $this->getJson('/api/schedule-history?type=archived&semester_id='.$this->first->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $version->id)
            ->assertJsonPath('data.0.academic_year', '2026-2027')->assertJsonPath('data.0.semester', '1st')
            ->assertJsonPath('data.0.snapshots.0.faculty_name', 'Original Instructor')
            ->assertJsonPath('data.0.snapshots.0.room_name', 'CC101')->assertJsonPath('data.0.snapshots.0.course_code', 'IT101')
            ->assertJsonPath('data.0.snapshots.0.section_name', 'IT1A')->assertJsonPath('data.0.snapshots.0.snapshot.meeting_type', 'lecture');
        $finalized = collect($history->json('data.0.snapshots'))->firstWhere('snapshot.status', 'finalized');
        $this->assertSame($faculty->id, $finalized['snapshot']['faculty_id']);
        $this->assertTrue((bool) $finalized['snapshot']['faculty_assignment_done']);
        $this->getJson('/api/schedule-history?type=archived&semester_id='.$this->second->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->activate($this->second)->assertOk()->assertJsonPath('history_version_ids', []);
        $this->assertSame(3, ScheduleHistoryVersion::count());
        $this->assertSame(1, Semester::where('is_active', true)->count());
        $this->assertTrue((bool) $this->second->fresh()->is_active);
        $this->assertFalse((bool) $this->summer->fresh()->is_enabled);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'schedule_semester_archived']);
    }

    public function test_duplicate_requests_are_idempotent_and_stale_confirmations_are_rejected(): void
    {
        $confirmation = ['expected_active_semester_id' => $this->first->id, 'expected_academic_year' => '2026-2027'];
        $this->activate($this->second, $confirmation)->assertOk();
        $count = SchedulingAuditLog::count();
        $this->activate($this->second, $confirmation)->assertOk();
        $this->assertSame($count, SchedulingAuditLog::count());
        $this->activate($this->first, ['expected_active_semester_id' => $this->first->id])->assertConflict();
        $this->activate($this->first, ['expected_academic_year' => '2027-2028'])->assertConflict();
        $this->assertTrue((bool) $this->second->fresh()->is_active);
        $this->assertSame($count, SchedulingAuditLog::count());
    }

    public function test_the_first_activation_records_only_an_activated_event_and_checks_the_expected_empty_context(): void
    {
        $this->activate($this->second, ['expected_active_semester_id' => null])->assertConflict();
        $this->assertSame(0, SchedulingAuditLog::count());
        $this->first->update(['is_active' => false]);

        $this->activate($this->second, ['expected_active_semester_id' => null])->assertOk();

        $this->getJson('/api/semesters/activation-history')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.action', 'Activated')->assertJsonPath('0.semester', '2nd');
        $this->assertSame(1, Semester::where('is_active', true)->count());
    }

    public function test_summer_availability_and_activation_have_separate_server_guards(): void
    {
        $url = '/api/semesters/'.$this->summer->id;
        $this->activate($this->summer)->assertUnprocessable()->assertJsonValidationErrors('is_enabled');
        $this->patchJson($url, ['is_enabled' => true])->assertUnprocessable();
        $this->activate($this->second)->assertOk();
        $this->patchJson($url, ['is_enabled' => true])->assertOk();
        $this->assertTrue((bool) $this->second->fresh()->is_active);
        $this->activate($this->summer)->assertOk();
        $this->patchJson($url, ['is_enabled' => false])->assertUnprocessable();
        $this->activate($this->first)->assertOk();
        $this->assertTrue((bool) $this->summer->fresh()->is_enabled);
        $this->patchJson('/api/semesters/'.$this->first->id, ['is_enabled' => false])->assertUnprocessable();
    }

    public function test_only_unused_inactive_academic_years_can_be_changed(): void
    {
        $url = '/api/semesters/'.$this->second->id;
        $this->patchJson($url, ['academic_year' => '2027-2029'])->assertUnprocessable()->assertJsonValidationErrors('academic_year');
        $this->patchJson($url, ['academic_year' => '2027-2028'])->assertOk()->assertJsonPath('semester.can_edit_academic_year', true);
        $this->patchJson('/api/semesters/'.$this->first->id, ['academic_year' => '2027-2028'])->assertUnprocessable();
        $this->activate($this->second)->assertOk()->assertJsonPath('semester.can_edit_academic_year', false);
        $this->activate($this->first)->assertOk();
        $this->patchJson($url, ['academic_year' => '2028-2029'])->assertUnprocessable()->assertJsonValidationErrors('academic_year');
        $this->getJson('/api/semesters/'.$this->second->id)->assertOk()->assertJsonPath('academic_year', '2027-2028')->assertJsonPath('can_edit_academic_year', false);

        $unused = Semester::create(['semester' => '2nd', 'academic_year' => '2028-2029', 'is_active' => false]);
        $this->patchJson('/api/semesters/'.$unused->id, ['academic_year' => '2027-2028'])->assertUnprocessable();
    }

    public function test_preparing_another_academic_year_preserves_used_periods_and_does_not_activate_or_enable_summer(): void
    {
        $used = $this->first->fresh()->getAttributes();
        $this->postJson('/api/semesters', ['semester' => '1st', 'academic_year' => '2027-2028', 'is_active' => true])
            ->assertCreated()->assertJsonPath('semester.academic_year', '2027-2028')
            ->assertJsonPath('semester.is_active', false)->assertJsonPath('semester.is_enabled', true)
            ->assertJsonPath('semester.can_edit_academic_year', true);
        $this->postJson('/api/semesters', ['semester' => 'summer', 'academic_year' => '2027-2028', 'is_enabled' => true])
            ->assertCreated()->assertJsonPath('semester.is_active', false)->assertJsonPath('semester.is_enabled', false);

        $this->assertSame($used, $this->first->fresh()->getAttributes());
        $this->assertSame(1, Semester::where('is_active', true)->count());
        $this->assertSame(0, ScheduleHistoryVersion::count());
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'semester_activated']);
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'semester_ended']);
    }

    public function test_preparing_a_period_rejects_invalid_or_duplicate_years_without_creating_records(): void
    {
        $this->postJson('/api/semesters', ['semester' => '1st', 'academic_year' => '2027-2029'])
            ->assertUnprocessable()->assertJsonValidationErrors('academic_year');
        $this->postJson('/api/semesters', ['semester' => '1st', 'academic_year' => '2026-2027'])
            ->assertUnprocessable()->assertJsonPath('message', 'This academic semester already exists.');
        $this->assertSame(3, Semester::count());

        $this->actingAs(User::factory()->create(['role' => 'secretary']))
            ->postJson('/api/semesters', ['semester' => '1st', 'academic_year' => '2027-2028'])->assertForbidden();
        $this->assertSame(3, Semester::count());
    }

    public function test_history_records_event_identity_actor_and_actual_transition_time_without_inventing_legacy_values(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->activate($this->second)->assertOk();
        $occurredAt = now()->toISOString();
        $this->vpaa->update(['name' => 'Renamed VPAA']);
        $this->travel(1)->hour();
        $this->activate($this->first)->assertOk();
        // Event identity stays fixed even if a period is changed outside the API guard.
        $this->second->update(['academic_year' => '2030-2031']);
        $this->getJson('/api/semesters/activation-history')->assertOk()
            ->assertHeader('X-Timezone', config('app.timezone'))
            ->assertJsonCount(4)
            ->assertJsonPath('0.action', 'Activated')->assertJsonPath('0.semester', '1st')
            ->assertJsonPath('1.action', 'Ended')->assertJsonPath('1.semester', '2nd')
            ->assertJsonPath('2.action', 'Activated')->assertJsonPath('2.academic_year', '2026-2027')
            ->assertJsonPath('2.changed_by', 'Recorded VPAA')->assertJsonPath('2.occurred_at', $occurredAt)
            ->assertJsonPath('3.action', 'Ended')->assertJsonMissingPath('0.is_active');

        SchedulingAuditLog::create(['action' => 'schedule_semester_archived', 'metadata' => ['activated_semester_id' => $this->summer->id], 'created_at' => now()->addSecond()]);
        $this->getJson('/api/semesters/activation-history')->assertOk()->assertJsonCount(5)
            ->assertJsonPath('0.action', 'Activated')->assertJsonPath('0.academic_year', null)
            ->assertJsonPath('0.changed_by', null)->assertJsonPath('0.identity_recorded', false);
    }

    public function test_activation_rolls_back_the_entire_transition_if_recording_history_fails(): void
    {
        Event::listen('eloquent.creating: '.SchedulingAuditLog::class, function (SchedulingAuditLog $log) {
            if ($log->action === 'semester_activated') {
                throw new \RuntimeException('Injected activation audit failure');
            }
        });
        $this->activate($this->second)->assertStatus(500);
        $this->assertTrue((bool) $this->first->fresh()->is_active);
        $this->assertFalse((bool) $this->second->fresh()->is_active);
        $this->assertSame(0, SchedulingAuditLog::count());
    }

    public function test_archived_recovery_remains_available_and_unauthorized_changes_are_blocked(): void
    {
        $this->deleteJson('/api/semesters/'.$this->second->id)->assertOk();
        $this->getJson('/api/archives?type=semesters')->assertOk()->assertJsonFragment(['type' => 'semesters']);
        $this->postJson('/api/archives/semesters/'.$this->second->id.'/restore')->assertOk();
        $this->assertFalse((bool) $this->second->fresh()->is_active);
        $this->actingAs(User::factory()->create(['role' => 'secretary']));
        $this->activate($this->second)->assertForbidden();
        $this->getJson('/api/semesters/activation-history')->assertForbidden();
    }

    private function activate(Semester $semester, array $payload = [])
    {
        return $this->patchJson('/api/semesters/'.$semester->id.'/activate', $payload);
    }
}
