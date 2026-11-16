<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The VPAA can set another account's password directly from User Management. This is separate
 * from the self-service Forgot/Reset Password flow and must not disturb it.
 */
class VpaaChangeUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'NewSecret123';

    private User $vpaa;

    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $department = Departments::create([
            'department_code' => 'CCS',
            'department_name' => 'College of Computer Studies',
        ]);

        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true]);
        $this->vpaa->syncRoles(['vpaa']);
        $this->dean = User::factory()->create([
            'role' => 'dean',
            'is_active' => true,
            'department_id' => $department->id,
            'password' => 'OldSecret123',
        ]);
        $this->dean->syncRoles(['dean']);
    }

    public function test_vpaa_sets_a_hashed_password_that_works_immediately(): void
    {
        $this->changePassword($this->vpaa, $this->dean)->assertOk();

        $stored = $this->dean->fresh()->password;
        $this->assertNotSame(self::NEW_PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $stored));

        $this->postJson('/api/login', ['username' => $this->dean->username, 'password' => 'OldSecret123'])
            ->assertStatus(401);
        $this->postJson('/api/login', ['username' => $this->dean->username, 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    public function test_existing_sessions_are_revoked_and_the_change_is_audited(): void
    {
        $this->dean->createToken('wicars');

        $this->changePassword($this->vpaa, $this->dean)->assertOk();

        $this->assertSame(0, $this->dean->tokens()->count());
        $log = DB::table('authentication_audit_logs')->where('event', 'user_password_changed')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) $log->metadata);
    }

    public function test_non_vpaa_roles_are_refused(): void
    {
        $other = User::factory()->create(['role' => 'secretary', 'is_active' => true, 'department_id' => $this->dean->department_id]);
        $other->syncRoles(['secretary']);

        $this->changePassword($this->dean, $other)->assertForbidden();
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $other->fresh()->password));
    }

    public function test_guests_are_refused(): void
    {
        $this->putJson("/api/user/{$this->dean->id}/password", [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnauthorized();
    }

    public function test_the_vpaa_account_cannot_be_changed_here(): void
    {
        $this->changePassword($this->vpaa, $this->vpaa)->assertForbidden();
    }

    public function test_weak_or_unconfirmed_passwords_are_rejected(): void
    {
        $this->changePassword($this->vpaa, $this->dean, 'short')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->changePassword($this->vpaa, $this->dean, 'alllowercase123')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->changePassword($this->vpaa, $this->dean, self::NEW_PASSWORD, 'Mismatch12345')
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('OldSecret123', $this->dean->fresh()->password));
    }

    public function test_forgot_password_flow_is_unaffected(): void
    {
        $token = Password::createToken($this->dean);

        $this->changePassword($this->vpaa, $this->dean)->assertOk();

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $this->dean->email,
            'password' => 'ResetSecret456',
            'password_confirmation' => 'ResetSecret456',
        ])->assertOk();
        $this->assertTrue(Hash::check('ResetSecret456', $this->dean->fresh()->password));
    }

    private function changePassword(User $actor, User $target, string $password = self::NEW_PASSWORD, ?string $confirmation = null)
    {
        return $this->actingAs($actor, 'sanctum')->putJson("/api/user/{$target->id}/password", [
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }
}
