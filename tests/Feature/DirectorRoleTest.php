<?php

namespace Tests\Feature;

use App\Models\AuthenticationAuditLog;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\SystemNotificationService;
use App\Support\AccountVersion;
use App\Support\CapabilityRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Director is a department office role: Secretary's workflow, permissions and scope, sharing one
 * department slot with Secretary. Workflow parity is covered by tests/Feature/DirectorParity,
 * which reruns the Secretary suites as a Director.
 */
class DirectorRoleTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_10_110000_add_director_role_with_shared_department_office_slot.php';

    private Departments $ccs;

    private Departments $cas;

    private User $vpaa;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->ccs = Departments::create(['department_code' => 'CCS', 'department_name' => 'College of Computer Studies']);
        $this->cas = Departments::create(['department_code' => 'CAS', 'department_name' => 'College of Arts and Sciences']);
        Program::create(['department_id' => $this->ccs->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        Program::create(['department_id' => $this->cas->id, 'code' => 'ABE', 'name' => 'English']);
        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true]);
    }

    // ---- Capabilities -------------------------------------------------------------------------

    public function test_director_holds_exactly_the_secretary_capabilities(): void
    {
        $defaults = app(CapabilityRegistry::class)->roleDefaults();
        $this->assertSame($defaults['secretary'], $defaults['director']);

        $director = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id]);
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $this->cas->id]);
        $this->assertEqualsCanonicalizing($secretary->capabilityNames(), $director->capabilityNames());
        $this->assertFalse($director->hasCapability('schedule.approve_dean'));
        $this->assertFalse($director->hasCapability('schedule.approve_vpaa'));
        $this->assertFalse($director->hasCapability('faculty.manage_designations'));
    }

    public function test_director_has_no_approval_or_vpaa_administration_access(): void
    {
        $director = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id]);

        $this->actingAs($director, 'sanctum')->postJson("/api/departments/{$this->ccs->id}/approve-by-dean")->assertForbidden();
        $this->actingAs($director, 'sanctum')->postJson("/api/departments/{$this->ccs->id}/approve-by-vpaa")->assertForbidden();
        $this->actingAs($director, 'sanctum')->getJson('/api/user')->assertForbidden();
        $this->actingAs($director, 'sanctum')->getJson('/api/archives')->assertForbidden();
        $this->actingAs($director, 'sanctum')->getJson('/api/activity-log')->assertForbidden();
    }

    // ---- Accounts and the shared slot ---------------------------------------------------------

    public function test_vpaa_creates_a_director_with_department_scope_and_no_program(): void
    {
        $id = $this->createAccount('dir', ['role' => 'director', 'program_id' => Program::first()->id])
            ->assertCreated()
            ->assertJsonPath('data.role', 'director')
            ->json('data.id');

        $director = User::findOrFail($id);
        $this->assertSame($this->ccs->id, $director->department_id);
        $this->assertNull($director->program_id);
        $this->assertTrue($director->hasRole('director'));
        $this->assertTrue($director->hasCapability('schedule.create'));
    }

    public function test_director_requires_a_department(): void
    {
        $this->createAccount('dir', ['role' => 'director', 'department_id' => null])
            ->assertJsonValidationErrors('department_id');
    }

    public function test_secretary_blocks_director_and_director_blocks_secretary_in_one_department(): void
    {
        $this->createAccount('sec')->assertCreated();
        $this->createAccount('dir', ['role' => 'director'])
            ->assertJsonValidationErrors(['role' => 'CCS already has an active Secretary']);

        $this->createAccount('dir2', ['role' => 'director', 'department_id' => $this->cas->id])->assertCreated();
        $this->createAccount('sec2', ['department_id' => $this->cas->id])
            ->assertJsonValidationErrors(['role' => 'CAS already has an active Director']);
    }

    public function test_the_occupied_slot_message_names_the_shared_rule(): void
    {
        $this->createAccount('dir', ['role' => 'director'])->assertCreated();

        $message = $this->createAccount('sec')->assertUnprocessable()->json('errors.role.0');
        $this->assertStringContainsString('one Secretary or one Director, not both', $message);
    }

    public function test_different_departments_are_independent(): void
    {
        $this->createAccount('sec')->assertCreated();
        $this->createAccount('dir', ['role' => 'director', 'department_id' => $this->cas->id])->assertCreated();
    }

    public function test_inactive_and_archived_accounts_release_the_slot(): void
    {
        $secretary = $this->createAccount('sec')->assertCreated()->json('data.id');
        $this->createAccount('dir', ['role' => 'director', 'is_active' => false])->assertCreated();

        $this->updateAccount($secretary, ['is_active' => false])->assertOk();
        $director = $this->createAccount('dir2', ['role' => 'director'])->assertCreated()->json('data.id');

        $this->actingAs($this->vpaa, 'sanctum')->deleteJson("/api/user/{$director}")->assertOk();
        $this->updateAccount($secretary, ['is_active' => true])->assertOk();
    }

    public function test_switching_one_account_between_secretary_and_director_is_allowed(): void
    {
        $id = $this->createAccount('office')->assertCreated()->json('data.id');

        $this->updateAccount($id, ['role' => 'director'])->assertOk()->assertJsonPath('data.role', 'director');
        $user = User::findOrFail($id);
        $this->assertTrue($user->hasRole('director'));
        $this->assertFalse($user->hasRole('secretary'));

        $this->updateAccount($id, ['role' => 'secretary'])->assertOk();
        $this->assertTrue(User::findOrFail($id)->hasRole('secretary'));
    }

    public function test_activation_and_department_transfer_respect_the_shared_slot(): void
    {
        $this->createAccount('sec')->assertCreated();
        $director = $this->createAccount('dir', ['role' => 'director', 'department_id' => $this->cas->id])->assertCreated()->json('data.id');

        // Moving the CAS Director into CCS collides with the CCS Secretary.
        $this->updateAccount($director, ['department_id' => $this->ccs->id])->assertJsonValidationErrors('role');

        // An inactive Director may move, but cannot be activated while the Secretary holds the slot.
        $this->updateAccount($director, ['department_id' => $this->ccs->id, 'is_active' => false])->assertOk();
        $this->updateAccount($director, ['is_active' => true])->assertJsonValidationErrors('role');
        $this->assertFalse(User::findOrFail($director)->is_active);
    }

    public function test_director_reuses_the_secretary_faculty_profile_behaviour(): void
    {
        $id = $this->createAccount('dir', ['role' => 'director', 'faculty_mode' => 'create'])->assertCreated()->json('data.id');

        $profile = Faculty::query()->where('user_id', $id)->firstOrFail();
        $this->assertSame('director', $profile->administrative_role);
        $this->assertSame($this->ccs->id, $profile->department_id);

        $this->updateAccount($id, ['role' => 'secretary'])->assertOk();
        $this->assertSame('secretary', $profile->fresh()->administrative_role);
    }

    public function test_a_concurrent_secretary_is_seen_under_the_scope_lock(): void
    {
        $competitorInserted = false;
        Departments::retrieved(function (Departments $department) use (&$competitorInserted): void {
            if ($competitorInserted || DB::transactionLevel() === 0) {
                return;
            }
            $competitorInserted = true;
            User::factory()->create(['role' => 'secretary', 'department_id' => $department->id, 'name' => 'Simultaneous Secretary']);
        });

        $this->createAccount('dir', ['role' => 'director'])->assertJsonValidationErrors(['role' => 'active Secretary']);
        $this->assertTrue($competitorInserted);
        $this->assertDatabaseMissing('users', ['username' => 'dir.account']);
    }

    public function test_the_database_rejects_an_active_secretary_and_director_in_one_department(): void
    {
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->ccs->id]);
        User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'is_active' => false]);
        User::factory()->create(['role' => 'director', 'department_id' => $this->cas->id]);
        $archived = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'is_active' => false]);
        $archived->delete();

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id]);
    }

    public function test_the_database_rejects_activating_a_director_beside_an_active_secretary(): void
    {
        // A writer that bypasses the application checks is still stopped by the unique index.
        $director = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'is_active' => false]);
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->ccs->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->where('id', $director->id)->update(['is_active' => true]);
    }

    public function test_archive_preview_and_restore_apply_the_shared_slot(): void
    {
        $director = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id]);
        $this->actingAs($this->vpaa, 'sanctum')->deleteJson("/api/user/{$director->id}")->assertOk();
        $this->actingAs($this->vpaa, 'sanctum')->getJson("/api/archives/users/{$director->id}")
            ->assertJsonPath('data.restore.state', 'clear');

        User::factory()->create(['role' => 'secretary', 'department_id' => $this->ccs->id, 'name' => 'Rival Secretary']);

        $this->actingAs($this->vpaa, 'sanctum')->getJson("/api/archives/users/{$director->id}")
            ->assertJsonPath('data.restore.state', 'blocked')
            ->assertJsonPath('data.restore.blockers.0.code', 'role_occupied');
        $response = $this->actingAs($this->vpaa, 'sanctum')->postJson("/api/archives/users/{$director->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('blockers.0.code', 'role_occupied');
        $this->assertStringContainsString('active Secretary', $response->json('blockers.0.message'));
        $this->assertSoftDeleted('users', ['id' => $director->id]);
    }

    public function test_restoring_a_director_into_a_free_department_works(): void
    {
        $director = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id]);
        $this->actingAs($this->vpaa, 'sanctum')->deleteJson("/api/user/{$director->id}")->assertOk();
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->cas->id]);

        $this->actingAs($this->vpaa, 'sanctum')->postJson("/api/archives/users/{$director->id}/restore")->assertOk();
        $this->assertNull($director->fresh()->deleted_at);
    }

    public function test_a_secretary_created_after_the_restore_preview_blocks_a_director_restore(): void
    {
        $director = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id]);
        $director->delete();
        $competitorInserted = false;
        Departments::retrieved(function (Departments $department) use (&$competitorInserted): void {
            if ($competitorInserted || DB::transactionLevel() === 0) {
                return;
            }
            $competitorInserted = true;
            User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        });

        $this->actingAs($this->vpaa, 'sanctum')->postJson("/api/archives/users/{$director->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('blockers.0.code', 'role_occupied');
        $this->assertTrue($competitorInserted);
        $this->assertSoftDeleted('users', ['id' => $director->id]);
    }

    // ---- Authentication -----------------------------------------------------------------------

    public function test_an_active_director_signs_in_with_a_password_and_google(): void
    {
        $director = User::factory()->create([
            'role' => 'director', 'department_id' => $this->ccs->id,
            'email' => 'director@school.edu.ph', 'password' => Hash::make('StrongPass123'),
        ]);

        $this->postJson('/api/login', ['username' => $director->username, 'password' => 'StrongPass123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'director');

        $this->assertSame('director', $this->googleLogin('director@school.edu.ph')->json('user.role'));
    }

    public function test_disabled_and_archived_directors_cannot_sign_in(): void
    {
        $disabled = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'is_active' => false, 'password' => Hash::make('StrongPass123')]);
        $this->postJson('/api/login', ['username' => $disabled->username, 'password' => 'StrongPass123'])->assertForbidden();

        $archived = User::factory()->create(['role' => 'director', 'department_id' => $this->cas->id, 'password' => Hash::make('StrongPass123')]);
        $archived->delete();
        $this->postJson('/api/login', ['username' => $archived->username, 'password' => 'StrongPass123'])->assertUnauthorized();
    }

    // ---- Legacy Director accounts -------------------------------------------------------------

    public function test_the_upgrade_quarantines_legacy_directors_and_vpaa_reactivation_restores_access(): void
    {
        [$legacy, $archived, $faculty] = $this->legacyDirectors();
        $token = $legacy->createToken('wicars')->plainTextToken;

        $this->runUpgrade();

        $legacy->refresh();
        $this->assertFalse($legacy->is_active);
        $this->assertSame('director', $legacy->role);
        $this->assertFalse($legacy->hasRole('director'));
        $this->assertSame(0, $legacy->tokens()->count());
        $this->assertSame($legacy->id, $faculty->fresh()->user_id, 'faculty link is preserved');
        $this->assertSoftDeleted('users', ['id' => $archived->id]);
        $this->assertFalse(User::withTrashed()->find($archived->id)->is_active);
        $this->assertSame(2, AuthenticationAuditLog::where('event', 'legacy_director_quarantined')->count());
        $this->assertEqualsCanonicalizing(
            app(CapabilityRegistry::class)->roleDefaults()['secretary'],
            Role::findByName('director', 'api')->permissions->pluck('name')->all(),
        );

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', ['username' => $legacy->username, 'password' => 'StrongPass123'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This account has been disabled. Contact the VPAA office.');

        // Approved reactivation reconciles the Spatie role although users.role did not change.
        $this->updateAccount($legacy->id, ['is_active' => true])->assertOk();
        $legacy->refresh();
        $this->assertTrue($legacy->hasRole('director'));
        $this->assertTrue($legacy->hasCapability('schedule.submit'));
        $this->postJson('/api/login', ['username' => $legacy->username, 'password' => 'StrongPass123'])->assertOk();
    }

    public function test_legacy_reactivation_is_refused_while_a_secretary_holds_the_slot(): void
    {
        [$legacy] = $this->legacyDirectors();
        $this->runUpgrade();
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->ccs->id]);

        $this->updateAccount($legacy->id, ['is_active' => true])->assertJsonValidationErrors(['role' => 'active Secretary']);
        $this->assertFalse($legacy->fresh()->is_active);
    }

    public function test_a_director_without_its_role_assignment_cannot_authenticate(): void
    {
        // Code deployed before the quarantine migration ran: the account is still active.
        $legacy = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'password' => Hash::make('StrongPass123')]);
        $legacy->syncRoles([]);
        $token = $legacy->createToken('wicars')->plainTextToken;

        $this->postJson('/api/login', ['username' => $legacy->username, 'password' => 'StrongPass123'])
            ->assertForbidden()
            ->assertJsonPath('message', User::ROLE_REVIEW_MESSAGE);
        $this->withToken($token)->getJson('/api/me')->assertForbidden();
        $this->assertSame(0, $legacy->tokens()->count());
    }

    public function test_the_upgrade_preflight_reports_departments_with_two_office_holders(): void
    {
        DB::statement('DROP INDEX IF EXISTS `users_active_department_office_unique`');
        // The Director role already exists here, so the upgrade quarantines nothing.
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->ccs->id, 'username' => 'ccs.sec']);
        User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'username' => 'ccs.dir']);

        try {
            $this->runUpgrade();
            $this->fail('The preflight should refuse duplicate Secretary/Director holders.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('one active Secretary or one active Director', $exception->getMessage());
            $this->assertStringContainsString('@ccs.sec (secretary)', $exception->getMessage());
            $this->assertStringContainsString('@ccs.dir (director)', $exception->getMessage());
        }
    }

    // ---- Notifications ------------------------------------------------------------------------

    public function test_director_receives_secretary_notifications_within_its_department_only(): void
    {
        $director = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id]);
        $otherDirector = User::factory()->create(['role' => 'director', 'department_id' => $this->cas->id]);

        app(SystemNotificationService::class)->notifyRoles(['secretary', 'program_head'], 'schedule_returned_by_dean', 'Returned', 'Returned for revision', departmentId: $this->ccs->id);
        app(SystemNotificationService::class)->notifyRoles(['dean'], 'dean_only', 'Dean only', 'Not for directors', departmentId: $this->ccs->id);

        $this->assertSame(1, SystemNotification::where('user_id', $director->id)->count());
        $this->assertSame('schedule_returned_by_dean', SystemNotification::where('user_id', $director->id)->value('type'));
        $this->assertSame(0, SystemNotification::where('user_id', $otherDirector->id)->count());
    }

    public function test_department_personnel_lists_the_active_director(): void
    {
        User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'name' => 'Active Director']);
        User::factory()->create(['role' => 'secretary', 'department_id' => $this->ccs->id, 'name' => 'Former Secretary', 'is_active' => false]);

        $department = collect($this->actingAs($this->vpaa, 'sanctum')->getJson('/api/departments')->assertOk()->json())
            ->firstWhere('id', $this->ccs->id);
        $this->assertSame(['director' => 'Active Director'], collect($department['users'])->pluck('name', 'role')->all());
    }

    // ---- Helpers ------------------------------------------------------------------------------

    /** @return array{0: User, 1: User, 2: Faculty} */
    private function legacyDirectors(): array
    {
        // The retired state left by 2026_09_27_100002: users.role kept, Spatie role removed.
        (require base_path('database/migrations/2026_09_27_100002_remove_legacy_director_role.php'))->up();

        $legacy = User::factory()->create(['role' => 'director', 'department_id' => $this->ccs->id, 'password' => Hash::make('StrongPass123')]);
        $archived = User::factory()->create(['role' => 'director', 'department_id' => $this->cas->id]);
        $archived->delete();
        $faculty = Faculty::create(['first_name' => 'Lea', 'last_name' => 'Gacy', 'employment_type' => 'full-time', 'department_id' => $this->ccs->id, 'user_id' => $legacy->id, 'administrative_role' => 'director']);

        return [$legacy, $archived, $faculty];
    }

    private function runUpgrade(): void
    {
        (require base_path(self::MIGRATION))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function googleLogin(string $email)
    {
        config()->set('services.google.client_id', 'client-id');
        config()->set('services.google.client_secret', 'client-secret');
        config()->set('services.google.redirect', 'http://localhost/api/auth/google/callback');
        config()->set('services.frontend_url', 'http://localhost:5173');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-sub-dir', 'email' => $email, 'email_verified' => true]),
        ]);

        $redirect = $this->getJson('/api/auth/google/redirect')->assertOk();
        parse_str(parse_url($redirect->json('url'), PHP_URL_QUERY), $query);
        $callback = $this->get('/api/auth/google/callback?'.http_build_query(['code' => 'oauth-code', 'state' => $query['state']]));
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $result);

        return $this->postJson('/api/auth/google/exchange', ['code' => $result['google_code'], 'state' => $result['google_state']])->assertOk();
    }

    private function createAccount(string $handle, array $overrides = [])
    {
        return $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/user', $overrides + [
            'first_name' => ucfirst($handle),
            'last_name' => 'Account',
            'username' => "{$handle}.account",
            'email' => "{$handle}.account@school.edu.ph",
            'role' => 'secretary',
            'department_id' => $this->ccs->id,
            'faculty_mode' => 'none',
        ]);
    }

    private function updateAccount(int $id, array $overrides)
    {
        $user = User::findOrFail($id);

        return $this->actingAs($this->vpaa, 'sanctum')->putJson("/api/user/{$id}", $overrides + [
            'expected_version' => AccountVersion::of(User::findOrFail($id)),
            'first_name' => $user->first_name ?: 'First',
            'last_name' => $user->last_name ?: 'Last',
            'email' => $user->email,
            'role' => $user->role,
            'department_id' => $user->department_id,
            'program_id' => $user->program_id,
            'is_active' => $user->is_active,
        ]);
    }
}
