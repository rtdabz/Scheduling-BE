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
            ->assertJsonPath('data.0.category', 'user_management')
            ->assertJsonPath('data.0.actor.id', $vpaa->id)
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
            ->getJson('/api/activity-log?category=schedule_workflow')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'schedule_approved_by_vpaa');

        $response = $this->actingAs($vpaa, 'sanctum')
            ->get('/api/activity-log?export=csv&category=schedule_workflow')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('schedule_approved_by_vpaa', $response->streamedContent());
        $this->assertStringNotContainsString('login_succeeded', $response->streamedContent());
    }
}
