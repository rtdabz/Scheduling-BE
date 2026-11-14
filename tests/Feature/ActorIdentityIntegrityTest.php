<?php

namespace Tests\Feature;

use App\Jobs\DeliverSystemNotifications;
use App\Models\AuthenticationAuditLog;
use App\Models\Departments;
use App\Models\Program;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\SchedulingAuditLog;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\AuthenticationAuditService;
use App\Services\SystemNotificationService;
use App\Support\AccountVersion;
use App\Support\ActorIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActorIdentityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['dean', 'secretary', 'director', 'program_head']);
    }

    private function actor(string $role): User
    {
        $department = Departments::create(['department_name' => 'Integrity College', 'department_code' => 'INT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'INT', 'name' => 'Integrity Program']);

        return User::factory()->create(['name' => 'Original Actor', 'first_name' => 'Original', 'last_name' => 'Actor',
            'role' => $role, 'department_id' => $department->id, 'program_id' => $role === 'program_head' ? $program->id : null]);
    }

    public static function lifecycleRoles(): array
    {
        return [...self::roles(), ['vpaa']];
    }

    #[DataProvider('lifecycleRoles')]
    public function test_former_officials_remain_the_actors_after_rename_archive_and_replacement(string $role): void
    {
        $actor = $this->actor($role);
        $vpaa = $role === 'vpaa' ? $actor : User::factory()->create(['role' => 'vpaa']);
        $log = SchedulingAuditLog::create(['user_id' => $actor->id, 'department_id' => $actor->department_id, 'action' => 'schedule_submitted']);
        $version = ScheduleHistoryVersion::create(['actor_user_id' => $actor->id, 'action' => 'schedule_submitted']);
        $request = Request::create('/profile', 'PATCH');
        $request->setUserResolver(fn () => $actor);
        app(AuthenticationAuditService::class)->record($request, 'profile_updated', $actor);

        $actor->update(['name' => 'Changed Name', 'is_active' => false, 'role' => 'secretary']);
        $actor->delete();
        $replacement = User::factory()->create(['name' => 'New Official', 'role' => $role,
            'department_id' => $actor->department_id, 'program_id' => $actor->program_id]);

        $entry = collect($this->actingAs($role === 'vpaa' ? $replacement : $vpaa)->getJson('/api/activity-log')->assertOk()->json('data'))->firstWhere('id', 'scheduling:'.$log->id);
        $this->assertSame('Original Actor', $entry['actor']['name']);
        $this->assertSame($role, $entry['actor']['role']);
        $this->assertSame($actor->id, $entry['actor']['id']);
        $this->assertSame('Original Actor', $version->fresh()->recorded_actor['name']);
        $this->assertSame('Original Actor', AuthenticationAuditLog::first()->recorded_actor['name']);
        $new = SchedulingAuditLog::create(['user_id' => $replacement->id, 'action' => 'schedule_submitted']);
        $this->assertSame('New Official', $new->recorded_actor['name']);
        $this->assertNotSame($actor->id, $replacement->id);
    }

    #[DataProvider('roles')]
    public function test_status_only_updates_cannot_restore_stale_profile_fields(string $role): void
    {
        $actor = $this->actor($role);
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $staleVersion = AccountVersion::of($actor);
        $this->actingAs($actor)->patchJson('/api/profile', ['expected_version' => $staleVersion,
            'first_name' => 'Newer', 'last_name' => 'Name'])->assertOk();
        $this->actingAs($vpaa)->patchJson('/api/user/'.$actor->id.'/status', [
            'is_active' => false, 'first_name' => 'Stale', 'role' => 'secretary',
        ])->assertOk()->assertJsonPath('data.name', 'Newer Name')->assertJsonPath('data.role', $role);
        $actor->refresh();
        $this->assertFalse($actor->is_active);
        $this->putJson('/api/user/'.$actor->id, ['expected_version' => $staleVersion,
            'first_name' => 'Stale', 'last_name' => 'Actor', 'email' => $actor->email,
            'role' => $role, 'department_id' => $actor->department_id, 'program_id' => $actor->program_id,
            'is_active' => false])->assertConflict();
        $this->assertSame('Newer Name', $actor->fresh()->name);
        $status = AuthenticationAuditLog::where('event', 'user_status_changed')->firstOrFail();
        $this->assertTrue((bool) $status->metadata['before']['is_active']);
        $this->assertFalse((bool) $status->metadata['after']['is_active']);
        $this->assertSame($actor->program_id, $status->metadata['_subject']['program_id']);
    }

    public function test_native_database_guards_block_updates_deletes_and_actor_hard_deletion(): void
    {
        $actor = $this->actor('secretary');
        $log = SchedulingAuditLog::create(['user_id' => $actor->id, 'action' => 'schedule_submitted']);
        foreach (['update', 'delete', 'delete_actor'] as $operation) {
            try {
                match ($operation) {
                    'update' => DB::table('scheduling_audit_logs')->where('id', $log->id)->update(['user_id' => null]),
                    'delete' => DB::table('scheduling_audit_logs')->where('id', $log->id)->delete(),
                    default => DB::table('users')->where('id', $actor->id)->delete(),
                };
                $this->fail('The database allowed '.$operation.' of retained evidence.');
            } catch (QueryException) {
                $this->assertDatabaseHas('scheduling_audit_logs', ['id' => $log->id, 'user_id' => $actor->id]);
                $this->assertDatabaseHas('users', ['id' => $actor->id]);
            }
        }
    }

    public function test_all_event_tables_reject_direct_sql_mutation(): void
    {
        $actor = $this->actor('secretary');
        $version = ScheduleHistoryVersion::create(['actor_user_id' => $actor->id, 'action' => 'schedule_submitted']);
        $item = ScheduleHistoryItem::create(['history_version_id' => $version->id, 'after_snapshot' => ['status' => 'draft']]);
        $auth = AuthenticationAuditLog::create(['actor_user_id' => $actor->id, 'event' => 'logout']);
        foreach ([$version, $item, $auth] as $record) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $query = DB::table($record->getTable())->where('id', $record->id);
                    $operation === 'update' ? $query->update(['id' => $record->id]) : $query->delete();
                    $this->fail('Database permitted a history mutation.');
                } catch (QueryException) {
                    $this->assertDatabaseHas($record->getTable(), ['id' => $record->id]);
                }
            }
        }
    }

    public function test_queued_notifications_keep_the_identity_at_enqueue_time(): void
    {
        $actor = $this->actor('secretary');
        $recipient = User::factory()->create(['role' => 'vpaa']);
        $job = new DeliverSystemNotifications([['user_ids' => [$recipient->id], 'type' => 'schedule_approved_by_dean',
            'title' => 'Submitted', 'message' => 'Submitted', 'actor_id' => $actor->id,
            'department_id' => $actor->department_id, 'semester_id' => null, 'remarks' => null, 'metadata' => []]]);
        $actor->update(['name' => 'Changed After Enqueue']);
        $job->handle(app(SystemNotificationService::class));
        $notification = SystemNotification::firstOrFail();
        $this->assertSame('Original Actor', ActorIdentity::recorded($notification->metadata, $notification->actor_id)['name']);
    }

    public function test_legacy_self_identity_is_preserved_but_an_archived_subject_is_never_assumed_to_be_actor(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $snapshot = ['id' => 999, 'name' => 'Former Secretary', 'role' => 'secretary', 'username' => 'former', 'department_id' => null];
        DB::table('authentication_audit_logs')->insert([
            ['event' => 'invitation_accepted', 'metadata' => json_encode(['_subject' => $snapshot]), 'created_at' => now(), 'updated_at' => now()],
            ['event' => 'user_archived', 'metadata' => json_encode(['_subject' => $snapshot]), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $entries = collect($this->actingAs($vpaa)->getJson('/api/activity-log')->assertOk()->json('data'))->keyBy('event');
        $this->assertSame('Former Secretary', $entries['invitation_accepted']['actor']['name']);
        $this->assertNull($entries['user_archived']['actor']);
        $this->assertSame('unknown', $entries['user_archived']['actor_type']);
    }

    public function test_search_and_export_include_matches_behind_the_first_five_hundred_candidates(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        SchedulingAuditLog::create(['user_id' => $vpaa->id, 'action' => 'schedule_submitted',
            'metadata' => ['reference' => 'old-needle'], 'created_at' => now()->subDays(2)]);
        $newer = array_fill(0, 620, ['user_id' => $vpaa->id, 'action' => 'schedule_submitted', 'created_at' => now()]);
        DB::table('scheduling_audit_logs')->insert($newer);
        $this->actingAs($vpaa)->getJson('/api/activity-log?search=old-needle')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/activity-log?per_page=10&page=1')->assertOk()->assertJsonPath('meta.total', 621);
        $this->getJson('/api/activity-log?per_page=10&page=2')->assertOk()->assertJsonPath('meta.total', 621);
        $csv = $this->get('/api/activity-log?search=old-needle&export=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('old-needle', $csv);
    }
}
