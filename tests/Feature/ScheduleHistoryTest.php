<?php

namespace Tests\Feature;

use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_vpaa_can_read_schedule_history(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertForbidden();
    }

    public function test_vpaa_receives_newest_history_first(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $created = ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'action' => 'created', 'created_at' => now()->subMinute()]);
        ScheduleHistoryItem::create(['history_version_id' => $created->id, 'original_schedule_id' => 10, 'after_snapshot' => ['day' => 'Monday']]);
        $updated = ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'action' => 'updated', 'change_summary' => ['day' => 'Tuesday'], 'created_at' => now()]);
        ScheduleHistoryItem::create(['history_version_id' => $updated->id, 'original_schedule_id' => 10, 'after_snapshot' => ['day' => 'Tuesday']]);

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'updated')
            ->assertJsonPath('data.0.snapshot.day', 'Tuesday')
            ->assertJsonPath('data.1.action', 'created');
    }

    public function test_secretary_is_limited_to_their_department(): void
    {
        $departmentId = DB::table('departments')->insertGetId([
            'department_name' => 'Computing Studies',
            'department_code' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $departmentId]);
        $version = ScheduleHistoryVersion::create(['department_id' => $departmentId, 'action' => 'updated']);
        ScheduleHistoryItem::create(['history_version_id' => $version->id, 'after_snapshot' => ['day' => 'Friday']]);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_program_head_cannot_read_schedule_history(): void
    {
        $programHead = User::factory()->create(['role' => 'program_head']);

        $this->actingAs($programHead, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertForbidden();
    }
}
