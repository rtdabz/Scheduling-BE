<?php

namespace Tests\Feature;

use App\Models\ScheduleHistory;
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
        ScheduleHistory::create([
            'schedule_id' => 10,
            'actor_user_id' => $vpaa->id,
            'action' => 'created',
            'snapshot' => ['day' => 'Monday'],
            'created_at' => now()->subMinute(),
        ]);
        ScheduleHistory::create([
            'schedule_id' => 10,
            'actor_user_id' => $vpaa->id,
            'action' => 'updated',
            'snapshot' => ['day' => 'Tuesday'],
            'changes' => ['day' => 'Tuesday'],
            'created_at' => now(),
        ]);

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
        ScheduleHistory::create([
            'department_id' => $departmentId,
            'action' => 'updated',
            'snapshot' => ['day' => 'Friday'],
        ]);

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
