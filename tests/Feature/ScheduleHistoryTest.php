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

    /** The history endpoint lists review decisions: approved, rejected, recalled. */
    private const DECISION = ['source' => 'department_workflow', 'action' => 'schedule_approved_by_vpaa'];

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
        $older = ScheduleHistoryVersion::create([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()->subMinute()]);
        ScheduleHistoryItem::create(['history_version_id' => $older->id, 'original_schedule_id' => 10, 'after_snapshot' => ['day' => 'Monday']]);
        $newer = ScheduleHistoryVersion::create([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()]);
        ScheduleHistoryItem::create(['history_version_id' => $newer->id, 'original_schedule_id' => 10, 'after_snapshot' => ['day' => 'Tuesday']]);
        // Ordinary edits, submissions, and semester archives are not listed.
        ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'action' => 'updated']);
        ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'source' => 'department_workflow', 'action' => 'schedule_submitted']);
        ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'source' => 'semester_change', 'action' => 'schedule_semester_archived']);

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.snapshot.day', 'Tuesday')
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_history_filters_by_decision_type(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $workflow = ['source' => 'department_workflow', 'actor_user_id' => $vpaa->id];
        ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_approved_by_dean']);
        $rejected = ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_returned_by_dean', 'change_summary' => ['rejection_reason' => 'Room clash on Monday']]);
        $recalled = ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_withdrawn']);

        $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history')
            ->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history?type=rejected')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $rejected->id)
            ->assertJsonPath('data.0.rejection_reason', 'Room clash on Monday');
        $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history?type=recalled')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $recalled->id);
        $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history?type=archived')
            ->assertUnprocessable();
    }

    public function test_secretary_is_limited_to_their_department(): void
    {
        $departmentId = DB::table('departments')->insertGetId([
            'department_name' => 'Computing Studies',
            'department_code' => 'CCS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // The sibling test above covers the ungranted case; this one is about
        // department scoping, so the account holds the capability to read.
        $secretary = $this->grantCapabilities(
            User::factory()->create(['role' => 'secretary', 'department_id' => $departmentId]),
            ['schedule.view'],
        );
        $version = ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => $departmentId]);
        ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => null]);
        ScheduleHistoryItem::create(['history_version_id' => $version->id, 'after_snapshot' => ['day' => 'Friday']]);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_history_carries_department_logo_and_signatories(): void
    {
        $departmentId = DB::table('departments')->insertGetId([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
            'logo' => 'https://example.test/cit.png',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherId = DB::table('departments')->insertGetId(['department_name' => 'Education', 'department_code' => 'CED', 'created_at' => now(), 'updated_at' => now()]);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'name' => 'Vice President']);
        User::factory()->create(['role' => 'dean', 'department_id' => $departmentId, 'name' => 'The Dean']);
        User::factory()->create(['role' => 'secretary', 'department_id' => $departmentId, 'name' => 'The Secretary']);
        User::factory()->create(['role' => 'dean', 'department_id' => $otherId, 'name' => 'Other Dean']);
        ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => $departmentId]);

        $response = $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history')->assertOk()
            ->assertJsonPath('data.0.department.logo', 'https://example.test/cit.png')
            ->assertJsonPath('data.0.department.department_name', 'Information Technology');
        $names = collect($response->json('data.0.users'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['The Dean', 'The Secretary', 'Vice President'], $names);
    }

    public function test_program_head_cannot_read_schedule_history(): void
    {
        $programHead = User::factory()->create(['role' => 'program_head']);

        $this->actingAs($programHead, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertForbidden();
    }
}
