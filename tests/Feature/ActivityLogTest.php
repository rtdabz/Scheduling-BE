<?php

namespace Tests\Feature;

use App\Models\AuthenticationAuditLog;
use App\Models\SchedulingAuditLog;
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
}
