<?php

namespace Tests\Feature;

use App\Models\AuthenticationAuditLog;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\SchedulingAuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class ArchiveRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $vpaa;

    private Departments $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpaa = User::factory()->create(['role' => 'vpaa']);
        $this->department = Departments::create(['department_name' => 'Computing', 'department_code' => 'CCS']);
        Program::create(['department_id' => $this->department->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
    }

    public function test_a_failed_audit_write_leaves_the_record_and_profile_archived(): void
    {
        $user = User::factory()->create(['role' => 'dean', 'department_id' => $this->department->id]);
        $profile = Faculty::create(['first_name' => 'Ana', 'last_name' => 'Cruz', 'employment_type' => 'full-time', 'department_id' => $this->department->id, 'user_id' => $user->id, 'administrative_role' => null]);
        $user->delete();
        SchedulingAuditLog::creating(function (): void {
            throw new QueryException('sqlite', 'insert into scheduling_audit_logs', [], new PDOException('disk I/O error'));
        });

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson("/api/archives/users/{$user->id}/restore")
            ->assertStatus(500)
            ->assertJsonPath('code', 'database_error')
            ->assertJsonPath('message', 'The restore could not be completed because of a database error. Nothing was restored.');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertNull($profile->fresh()->administrative_role);
    }

    public function test_restoring_an_inactive_account_keeps_it_inactive_and_says_so(): void
    {
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id, 'is_active' => false]);
        $user->delete();

        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.status.label', 'Inactive account')
            ->assertJsonPath('data.outcome.0', 'The account returns to Users but stays inactive. It cannot sign in until it is activated from Users.');

        $response = $this->actingAs($this->vpaa, 'sanctum')
            ->postJson("/api/archives/users/{$user->id}/restore")
            ->assertOk()
            ->assertJsonPath('restored.0.status.label', 'Inactive account');

        $this->assertStringContainsString('stays inactive', $response->json('message'));
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_a_repeated_restore_is_reported_without_a_second_mutation(): void
    {
        $room = Rooms::create(['room_code' => 'R-1', 'room_type' => 'lecture']);
        $room->delete();
        $this->actingAs($this->vpaa, 'sanctum')->postJson("/api/archives/rooms/{$room->id}/restore")->assertOk();

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson("/api/archives/rooms/{$room->id}/restore")
            ->assertOk()
            ->assertJsonPath('already_restored', true)
            ->assertJsonPath('affected_count', 0);
        $this->assertSame(1, SchedulingAuditLog::where('action', 'record_restored')->count());

        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/rooms/{$room->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_archived');
    }

    public function test_a_role_holder_created_after_the_preview_blocks_the_restore(): void
    {
        $archived = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]);
        $archived->delete();
        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/users/{$archived->id}")
            ->assertJsonPath('data.restore.state', 'clear');

        // Another writer fills the slot after the preview; the locked recheck must see it.
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id, 'name' => 'Rival Holder']);

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson("/api/archives/users/{$archived->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('code', 'restore_blocked')
            ->assertJsonPath('blockers.0.code', 'role_occupied')
            ->assertJsonPath('blockers.0.record.type', 'users');
        $this->assertSoftDeleted('users', ['id' => $archived->id]);
    }

    public function test_account_creation_rechecks_the_role_slot_under_the_scope_lock(): void
    {
        $competitorInserted = false;
        Departments::retrieved(function (Departments $department) use (&$competitorInserted): void {
            if ($competitorInserted || DB::transactionLevel() === 0) {
                return;
            }
            $competitorInserted = true;
            User::factory()->create(['role' => 'dean', 'department_id' => $department->id, 'name' => 'Simultaneous Dean']);
        });

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/user', [
                'first_name' => 'New', 'last_name' => 'Dean', 'username' => 'newdean', 'email' => 'newdean@example.test',
                'role' => 'dean', 'department_id' => $this->department->id, 'is_active' => true, 'faculty_mode' => 'none',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertTrue($competitorInserted);
        $this->assertDatabaseMissing('users', ['username' => 'newdean']);
    }

    public function test_the_database_rejects_a_second_active_holder_in_one_scope(): void
    {
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]);
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id, 'is_active' => false]);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]);
    }

    public function test_known_blockers_are_listed_before_confirmation_and_enforced_on_restore(): void
    {
        $room = Rooms::create(['room_code' => 'DUP-1', 'room_type' => 'lecture', 'department_id' => $this->department->id]);
        $room->delete();
        Rooms::create(['room_code' => 'DUP-1', 'room_type' => 'lecture']);

        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson('/api/archives?type=rooms')
            ->assertOk()
            ->assertJsonPath('data.0.restore.state', 'blocked')
            ->assertJsonPath('data.0.restore.blockers.0.code', 'unique_conflict');

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson("/api/archives/rooms/{$room->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('code', 'restore_blocked')
            ->assertJsonPath('blockers.0.code', 'unique_conflict');
        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
    }

    public function test_archived_parents_are_linked_from_the_blocker(): void
    {
        $other = Departments::create(['department_name' => 'Closed', 'department_code' => 'CLO']);
        $room = Rooms::create(['room_code' => 'CLO-1', 'room_type' => 'lecture', 'department_id' => $other->id]);
        $room->delete();
        $other->delete();

        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.restore.blockers.0.code', 'archived_parent')
            ->assertJsonPath('data.restore.blockers.0.record', ['type' => 'departments', 'id' => $other->id, 'label' => 'Closed', 'archived' => true])
            ->assertJsonPath('data.context.department.archived', true);

        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/departments/{$other->id}")
            ->assertOk()
            ->assertJsonPath('data.archived_dependents.rooms', 1);
    }

    public function test_details_identify_accounts_and_never_expose_secrets(): void
    {
        $user = User::factory()->create([
            'role' => 'dean', 'department_id' => $this->department->id, 'name' => 'Maria Santos', 'username' => 'msantos2',
            'profile_picture' => 'data:image/png;base64,SECRETBLOB', 'google_id' => 'google-secret-id',
        ]);
        $this->actingAs($this->vpaa, 'sanctum')->deleteJson("/api/user/{$user->id}")->assertOk();

        $response = $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.subtitle', '@msantos2 · Dean')
            ->assertJsonPath('data.context.department.code', 'CCS')
            ->assertJsonPath('data.provenance.recorded', true)
            ->assertJsonPath('data.provenance.actor.username', $this->vpaa->username)
            ->assertJsonPath('data.provenance.reason', null);

        $body = $response->getContent();
        foreach (['SECRETBLOB', 'google-secret-id', 'password', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_unrecorded_provenance_is_reported_as_unknown_and_new_archives_are_recorded(): void
    {
        $legacy = Rooms::create(['room_code' => 'OLD-1', 'room_type' => 'lecture']);
        DB::table('rooms')->where('id', $legacy->id)->update(['deleted_at' => now()]);
        $room = Rooms::create(['room_code' => 'NEW-1', 'room_type' => 'lecture']);
        $this->actingAs($this->vpaa, 'sanctum')->deleteJson("/api/rooms/{$room->id}")->assertOk();

        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/rooms/{$legacy->id}")
            ->assertJsonPath('data.provenance.recorded', false)
            ->assertJsonPath('data.provenance.actor', null);
        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives/rooms/{$room->id}")
            ->assertJsonPath('data.provenance.recorded', true)
            ->assertJsonPath('data.provenance.actor.id', $this->vpaa->id);
    }

    public function test_the_list_is_paginated_filtered_and_stably_ordered_on_the_server(): void
    {
        $tie = Carbon::parse('2026-10-01 08:00:00');
        foreach (range(1, 23) as $index) {
            $room = Rooms::create(['room_code' => sprintf('PG-%02d', $index), 'room_type' => 'lecture', 'department_id' => $index <= 5 ? $this->department->id : null]);
            DB::table('rooms')->where('id', $room->id)->update(['deleted_at' => $tie]);
        }
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id, 'name' => 'Paged Person', 'is_active' => false]);
        DB::table('users')->where('id', $user->id)->update(['deleted_at' => $tie->copy()->addDay()]);

        $first = $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/archives?per_page=10')->assertOk()
            ->assertJsonPath('meta.total', 24)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('total_archived', 24)
            ->assertJsonPath('data.0.type', 'users')
            ->assertJsonCount(10, 'data');
        $pages = collect([1, 2, 3])->flatMap(fn (int $page) => $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/archives?per_page=10&page={$page}")->json('data.*.key'));
        $this->assertCount(24, $pages->unique());
        $this->assertSame($first->json('data.*.key'), $pages->take(10)->values()->all());

        $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/archives?search=PG-0&type=rooms')
            ->assertJsonPath('meta.total', 9)->assertJsonPath('counts.rooms', 9)->assertJsonPath('counts.users', 0);
        $this->actingAs($this->vpaa, 'sanctum')->getJson("/api/archives?department_id={$this->department->id}")
            ->assertJsonPath('meta.total', 6)->assertJsonPath('counts.rooms', 5)->assertJsonPath('counts.users', 1)->assertJsonPath('total_archived', 24);
        $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/archives?archived_from=2026-10-02&archived_to=2026-10-02')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.label', 'Paged Person');
        $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/archives?search=50%25')->assertJsonPath('meta.total', 0);
        $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/archives?per_page=10&page=99')->assertJsonPath('meta.current_page', 3);

        foreach (['sort=label', 'per_page=500', 'type=sections', 'search='.str_repeat('a', 101), 'archived_from=2026-10-05&archived_to=2026-10-01'] as $invalid) {
            $this->actingAs($this->vpaa, 'sanctum')->getJson("/api/archives?{$invalid}")->assertStatus(422);
        }
    }

    public function test_only_an_active_vpaa_can_read_details_or_restore(): void
    {
        $room = Rooms::create(['room_code' => 'AUTH-1', 'room_type' => 'lecture']);
        $room->delete();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $this->department->id]);

        $this->getJson("/api/archives/rooms/{$room->id}")->assertUnauthorized();
        $this->actingAs($dean, 'sanctum')->getJson("/api/archives/rooms/{$room->id}")->assertForbidden();
        $this->actingAs($dean, 'sanctum')->postJson("/api/archives/rooms/{$room->id}/restore")->assertForbidden();
        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
    }

    public function test_restorations_appear_under_the_restored_entity_in_the_activity_log(): void
    {
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id, 'is_active' => false]);
        $user->delete();
        $room = Rooms::create(['room_code' => 'ACT-1', 'room_type' => 'lecture', 'department_id' => $this->department->id]);
        $room->delete();
        $this->actingAs($this->vpaa, 'sanctum')->postJson("/api/archives/users/{$user->id}/restore")->assertOk();
        $this->actingAs($this->vpaa, 'sanctum')->postJson("/api/archives/rooms/{$room->id}/restore")->assertOk();

        $account = $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/activity-log?category=account_access&event=record_restored')->assertOk();
        $this->assertSame(1, $account->json('meta.total'));
        $account->assertJsonPath('data.0.target', ['type' => 'user', 'id' => $user->id])
            ->assertJsonPath('data.0.category', 'account_access')
            ->assertJsonPath('data.0.department_id', $this->department->id);

        $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/activity-log?category=institutional_setup&event=record_restored')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.target', ['type' => 'room', 'id' => $room->id]);
        $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/activity-log?category=scheduling&event=record_restored')
            ->assertJsonPath('meta.total', 0);
        $this->assertSame(0, AuthenticationAuditLog::where('event', 'record_restored')->count());
    }
}
