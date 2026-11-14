<?php

namespace Tests\Feature;

use App\Models\AuthenticationAuditLog;
use App\Models\SchedulingAuditLog;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_vpaa_can_read_activity_log(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/activity-log')
            ->assertForbidden();
    }

    public function test_vpaa_receives_normalized_combined_activity_newest_first(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $secretary = User::factory()->create(['role' => 'secretary']);
        $departmentId = DB::table('departments')->insertGetId([
            'department_name' => 'Computing Studies',
            'department_code' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SchedulingAuditLog::create([
            'user_id' => $secretary->id,
            'department_id' => $departmentId,
            'action' => 'schedule_submitted',
            'metadata' => ['schedules_updated' => 12],
            'created_at' => now()->subMinute(),
        ]);
        AuthenticationAuditLog::create([
            'actor_user_id' => $vpaa->id,
            'subject_user_id' => $secretary->id,
            'event' => 'user_updated',
            'metadata' => ['active' => true],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/activity-log')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.event', 'user_updated')
            ->assertJsonPath('data.0.category', 'account_access')
            ->assertJsonPath('data.1.event', 'schedule_submitted')
            ->assertJsonPath('data.1.department_id', $departmentId);
    }

    public function test_activity_log_filters_and_exports_csv(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        SchedulingAuditLog::create([
            'user_id' => $vpaa->id,
            'action' => 'schedule_approved_by_vpaa',
            'metadata' => ['schedules_updated' => 8],
            'created_at' => now(),
        ]);
        AuthenticationAuditLog::create([
            'actor_user_id' => $vpaa->id,
            'subject_user_id' => $vpaa->id,
            'event' => 'login_succeeded',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/activity-log?category=approval')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'schedule_approved_by_vpaa');

        $response = $this->actingAs($vpaa, 'sanctum')
            ->get('/api/activity-log?export=csv&category=approval')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('schedule_approved_by_vpaa', $response->streamedContent());
        $this->assertStringNotContainsString('login_succeeded', $response->streamedContent());
    }

    public function test_both_semester_transition_events_are_visible_as_academic_setup_with_their_period_targets(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $first = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true]);
        $second = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false]);
        $this->actingAs($vpaa)->patchJson('/api/semesters/'.$second->id.'/activate')->assertOk();

        $entries = collect($this->getJson('/api/activity-log?category=academic_setup')->assertOk()
            ->assertJsonPath('meta.total', 2)->json('data'))->keyBy('event');

        $this->assertSame('academic_setup', $entries['semester_activated']['category']);
        $this->assertSame(['type' => 'semester', 'id' => $second->id], $entries['semester_activated']['target']);
        $this->assertArrayNotHasKey('legacy_history', $entries['semester_activated']['metadata']);
        $this->assertSame('academic_setup', $entries['semester_ended']['category']);
        $this->assertSame(['type' => 'semester', 'id' => $first->id], $entries['semester_ended']['target']);
        $this->assertArrayNotHasKey('legacy_history', $entries['semester_ended']['metadata']);
        $this->getJson('/api/activity-log?category=academic_setup&event=semester_ended')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'semester_ended');
    }

    public function test_activity_log_supports_all_eight_categories_and_events(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);

        $events = [
            // Account & Access
            ['action' => 'login_succeeded', 'expected_category' => 'account_access'],
            // Institutional Setup
            ['action' => 'department_created', 'expected_category' => 'institutional_setup'],
            // Academic Setup
            ['action' => 'curriculum_created', 'expected_category' => 'academic_setup'],
            // Scheduling
            ['action' => 'schedule_created', 'expected_category' => 'scheduling'],
            ['action' => 'conflict_detected', 'expected_category' => 'scheduling'],
            ['action' => 'recommendation_generated', 'expected_category' => 'scheduling'],
            // Approval
            ['action' => 'schedule_submitted', 'expected_category' => 'approval'],
            ['action' => 'schedule_approved', 'expected_category' => 'approval'],
            // Instructor Assignment
            ['action' => 'instructor_assigned', 'expected_category' => 'instructor_assignment'],
            // Room Request
            ['action' => 'room_requested', 'expected_category' => 'room_request'],
            // Reports
            ['action' => 'schedule_report_generated', 'expected_category' => 'reports'],
        ];

        foreach ($events as $index => $item) {
            SchedulingAuditLog::create([
                'user_id' => $vpaa->id,
                'action' => $item['action'],
                'metadata' => ['test_index' => $index],
                'created_at' => now()->subMinutes(count($events) - $index),
            ]);
        }

        // Test each category filter
        foreach (['institutional_setup', 'academic_setup', 'scheduling', 'approval', 'instructor_assignment', 'room_request', 'reports'] as $category) {
            $res = $this->actingAs($vpaa, 'sanctum')
                ->getJson("/api/activity-log?category={$category}")
                ->assertOk();

            $entries = $res->json('data');
            $this->assertNotEmpty($entries);
            foreach ($entries as $entry) {
                $this->assertSame($category, $entry['category']);
            }
        }

        // Test event alias filtering
        SchedulingAuditLog::create([
            'user_id' => $vpaa->id,
            'action' => 'schedule_batch_deleted',
            'created_at' => now(),
        ]);
        $deletedRes = $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/activity-log?event=schedule_deleted')
            ->assertOk();
        $this->assertGreaterThanOrEqual(1, $deletedRes->json('meta.total'));
    }

    public function test_recorded_role_filter_actor_facets_and_summary_use_event_time_identity(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $dean = User::factory()->create(['role' => 'dean', 'name' => 'Dean Recorded']);
        SchedulingAuditLog::create(['user_id' => $dean->id, 'action' => 'schedule_approved_by_dean', 'created_at' => now()->subMinutes(3)]);
        SchedulingAuditLog::create(['user_id' => $dean->id, 'action' => 'schedule_returned_by_dean', 'created_at' => now()->subMinutes(2)]);
        SchedulingAuditLog::create(['user_id' => $vpaa->id, 'action' => 'schedule_approved_by_vpaa', 'created_at' => now()->subMinute()]);
        SchedulingAuditLog::create(['action' => 'schedule_reset', 'metadata' => ['_actor_type' => 'system'], 'created_at' => now()]);

        // Later account changes must not move earlier events to a different role or name.
        $dean->forceFill(['role' => 'program_head', 'name' => 'Renamed Later'])->save();

        $response = $this->actingAs($vpaa, 'sanctum')->getJson('/api/activity-log?role=dean')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.summary.approved', 1)
            ->assertJsonPath('meta.summary.returned', 1)
            ->assertJsonPath('meta.summary.actors', 1);
        // Facets ignore the actor/role selection itself so other identities stay selectable.
        $facets = collect($response->json('meta.facets.actors'));
        $this->assertSame('Dean Recorded', $facets->firstWhere('id', $dean->id)['name']);
        $this->assertSame('dean', $facets->firstWhere('id', $dean->id)['role']);
        $this->assertNotNull($facets->firstWhere('id', $vpaa->id));

        $this->getJson('/api/activity-log?role=program_head')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/activity-log?role=system')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'schedule_reset');
        $this->getJson('/api/activity-log?actor_id='.$vpaa->id)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'schedule_approved_by_vpaa');
        $this->getJson('/api/activity-log?role=registrar')->assertUnprocessable();
    }

    public function test_search_matches_readable_event_names(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        SchedulingAuditLog::create(['user_id' => $vpaa->id, 'action' => 'schedule_approved_by_vpaa', 'created_at' => now()]);

        $this->actingAs($vpaa, 'sanctum')->getJson('/api/activity-log?search=Schedule%20Approved')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_csv_export_neutralises_formulas_and_includes_identity_columns(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa', 'name' => '=HYPERLINK("http://x")', 'username' => 'vpaa.one']);
        SchedulingAuditLog::create(['user_id' => $vpaa->id, 'action' => 'schedule_approved_by_vpaa', 'created_at' => now()]);

        $csv = $this->actingAs($vpaa, 'sanctum')->get('/api/activity-log?export=csv')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame(['Entry ID', 'Timestamp', 'Category', 'Event', 'Status', 'Actor', 'Username', 'Recorded Role', 'Identity',
            'Department ID', 'Department Code', 'Semester ID', 'Target', 'Metadata'], $rows[0]);
        $this->assertSame('\'=HYPERLINK("http://x")', $rows[1][5]);
        $this->assertSame('vpaa.one', $rows[1][6]);
        $this->assertSame('vpaa', $rows[1][7]);
        $this->assertSame('recorded', $rows[1][8]);
    }

    public function test_audit_rows_without_explicit_time_use_the_application_clock(): void
    {
        $this->travelTo(now()->setTime(9, 30, 15));
        $user = User::factory()->create(['role' => 'vpaa']);

        $log = SchedulingAuditLog::create(['user_id' => $user->id, 'action' => 'room_request_approved']);

        $this->assertSame(now()->toDateTimeString(), $log->fresh()->created_at->toDateTimeString());
    }
}
