<?php

namespace Tests\Feature;

use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\User;
use App\Services\Scheduling\History\ScheduleHistorySummaries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** Review decisions remain available alongside semester snapshots. */
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
        $older = ScheduleHistoryVersion::forceCreate([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()->subMinute()]);
        ScheduleHistoryItem::create(['history_version_id' => $older->id, 'original_schedule_id' => 10, 'after_snapshot' => ['day' => 'Monday']]);
        $newer = ScheduleHistoryVersion::forceCreate([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()]);
        ScheduleHistoryItem::create(['history_version_id' => $newer->id, 'original_schedule_id' => 10, 'after_snapshot' => ['day' => 'Tuesday']]);
        // Ordinary edits and submissions are not listed; semester snapshots are.
        ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'action' => 'updated']);
        ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'source' => 'department_workflow', 'action' => 'schedule_submitted']);
        $archive = ScheduleHistoryVersion::forceCreate(['actor_user_id' => $vpaa->id, 'source' => 'semester_change', 'action' => 'schedule_semester_archived', 'created_at' => now()->subMinutes(2)]);

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.snapshot.day', 'Tuesday')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.2.id', $archive->id);
    }

    public function test_history_lists_only_vpaa_approved_schedules(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $workflow = ['source' => 'department_workflow', 'actor_user_id' => $vpaa->id];
        // Never final: dean decisions, returns, recalls and pending submissions.
        ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_approved_by_dean']);
        ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_returned_by_dean', 'change_summary' => ['rejection_reason' => 'Room clash on Monday']]);
        ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_returned_by_vpaa']);
        ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_withdrawn']);
        ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_submitted']);
        // A VPAA action name recorded by another source is not a workflow approval.
        ScheduleHistoryVersion::create(['source' => 'semester_change', 'action' => 'schedule_approved_by_vpaa']);
        $approved = ScheduleHistoryVersion::create([...$workflow, 'action' => 'schedule_approved_by_vpaa']);
        $archived = ScheduleHistoryVersion::create(['actor_user_id' => $vpaa->id, 'source' => 'semester_change', 'action' => 'schedule_semester_archived']);

        $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/schedule-history?type=approved')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $approved->id);
        $this->getJson('/api/schedule-history?type=archived')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $archived->id);
        foreach (['rejected', 'recalled', 'updated'] as $retired) {
            $this->getJson("/api/schedule-history?type={$retired}")->assertUnprocessable();
        }
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
        $archive = ScheduleHistoryVersion::create(['source' => 'semester_change', 'action' => 'schedule_semester_archived', 'department_id' => $departmentId]);
        ScheduleHistoryVersion::create(['source' => 'semester_change', 'action' => 'schedule_semester_archived', 'department_id' => null]);
        ScheduleHistoryItem::create(['history_version_id' => $version->id, 'after_snapshot' => ['day' => 'Friday']]);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->getJson('/api/schedule-history?type=archived')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $archive->id);
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
        $version = ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => $departmentId]);

        $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history')->assertOk()
            ->assertJsonPath('data.0.department.logo', 'https://example.test/cit.png')
            ->assertJsonPath('data.0.department.department_name', 'Information Technology')
            // Fallback signatory accounts are detail-only, so the list stays small.
            ->assertJsonMissingPath('data.0.users')
            ->assertJsonMissingPath('data.0.snapshots');
        $response = $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history/'.$version->id)->assertOk()
            ->assertJsonPath('data.department.logo', 'https://example.test/cit.png');
        $names = collect($response->json('data.users'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['The Dean', 'The Secretary', 'Vice President'], $names);
    }

    public function test_program_head_cannot_read_schedule_history(): void
    {
        $programHead = User::factory()->create(['role' => 'program_head']);

        $this->actingAs($programHead, 'sanctum')
            ->getJson('/api/schedule-history')
            ->assertForbidden();
    }

    public function test_list_summarizes_counts_and_label_without_class_rows(): void
    {
        $departmentId = DB::table('departments')->insertGetId(['department_name' => 'Engineering', 'department_code' => 'COE', 'created_at' => now(), 'updated_at' => now()]);
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $multi = ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => $departmentId]);
        foreach ([[1, 7], [2, 7], [3, 8]] as [$scheduleId, $sectionId]) {
            ScheduleHistoryItem::create(['history_version_id' => $multi->id, 'original_schedule_id' => $scheduleId,
                'after_snapshot' => ['section_id' => $sectionId, 'course_id' => 50 + $scheduleId], 'snapshot_metadata' => ['section_name' => 'S'.$sectionId]]);
        }
        $single = ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => $departmentId]);
        // Legacy rows may only carry the section in metadata.
        ScheduleHistoryItem::create(['history_version_id' => $single->id, 'original_schedule_id' => 9,
            'before_snapshot' => ['day' => 'Friday'], 'snapshot_metadata' => ['section_id' => 4, 'section_name' => 'BSCE 1A']]);

        $list = $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history')->assertOk();
        $rows = collect($list->json('data'))->keyBy('id');

        $this->assertSame([3, 2, 'Engineering Department Schedule', 1, 7, 51], [
            $rows[$multi->id]['schedule_count'], $rows[$multi->id]['section_count'], $rows[$multi->id]['schedule_label'],
            $rows[$multi->id]['schedule_id'], $rows[$multi->id]['section_id'], $rows[$multi->id]['course_id'],
        ]);
        $this->assertSame([1, 1, 'Engineering · BSCE 1A', 'Friday'], [
            $rows[$single->id]['schedule_count'], $rows[$single->id]['section_count'], $rows[$single->id]['schedule_label'], $rows[$single->id]['snapshot']['day'],
        ]);
        $this->assertArrayNotHasKey('snapshots', $rows[$multi->id]);

        $detail = $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history/'.$multi->id)->assertOk()
            ->assertJsonPath('data.schedule_count', 3)
            ->assertJsonPath('data.section_count', 2)
            ->assertJsonPath('data.schedule_label', 'Engineering Department Schedule')
            ->assertJsonCount(3, 'data.snapshots');
        $this->assertSame([1, 2, 3], collect($detail->json('data.snapshots'))->pluck('schedule_id')->all());
    }

    public function test_detail_is_scoped_like_the_list(): void
    {
        $own = DB::table('departments')->insertGetId(['department_name' => 'Own', 'department_code' => 'OWN', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('departments')->insertGetId(['department_name' => 'Other', 'department_code' => 'OTH', 'created_at' => now(), 'updated_at' => now()]);
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $own]);
        $mine = ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => $own]);
        $theirs = ScheduleHistoryVersion::create([...self::DECISION, 'department_id' => $other]);
        $pending = ScheduleHistoryVersion::create(['source' => 'department_workflow', 'action' => 'schedule_submitted', 'department_id' => $own]);

        $this->actingAs($dean, 'sanctum')->getJson('/api/schedule-history/'.$mine->id)->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->actingAs($dean, 'sanctum')->getJson('/api/schedule-history/'.$theirs->id)->assertNotFound();
        $this->actingAs($dean, 'sanctum')->getJson('/api/schedule-history/'.$pending->id)->assertNotFound();
    }

    public function test_list_summary_is_cached_for_settled_versions_only(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $settled = ScheduleHistoryVersion::forceCreate([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()->subHour()]);
        ScheduleHistoryItem::create(['history_version_id' => $settled->id, 'original_schedule_id' => 1, 'after_snapshot' => ['section_id' => 1]]);
        $fresh = ScheduleHistoryVersion::forceCreate([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()]);
        ScheduleHistoryItem::create(['history_version_id' => $fresh->id, 'original_schedule_id' => 2, 'after_snapshot' => ['section_id' => 2]]);

        $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history')->assertOk();

        $this->assertSame(1, Cache::get(ScheduleHistorySummaries::key($settled->id))['count'] ?? null);
        $this->assertNull(Cache::get(ScheduleHistorySummaries::key($fresh->id)));
    }

    public function test_warm_command_caches_settled_summaries(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $settled = ScheduleHistoryVersion::forceCreate([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()->subHour()]);
        ScheduleHistoryItem::create(['history_version_id' => $settled->id, 'original_schedule_id' => 1, 'after_snapshot' => ['section_id' => 1]]);
        ScheduleHistoryItem::create(['history_version_id' => $settled->id, 'original_schedule_id' => 2, 'after_snapshot' => ['section_id' => 2]]);
        $fresh = ScheduleHistoryVersion::forceCreate([...self::DECISION, 'actor_user_id' => $vpaa->id, 'created_at' => now()]);

        $this->artisan('schedule-history:warm-summaries')->expectsOutputToContain('1 schedule history version')->assertSuccessful();

        $this->assertSame(['count' => 2, 'sections' => 2], array_intersect_key(Cache::get(ScheduleHistorySummaries::key($settled->id)), ['count' => 0, 'sections' => 0]));
        $this->assertNull(Cache::get(ScheduleHistorySummaries::key($fresh->id)));
    }
}
