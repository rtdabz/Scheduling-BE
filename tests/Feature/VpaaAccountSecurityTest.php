<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\User;
use App\Notifications\RecoveryEmailVerificationNotification;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded VPAA account links a verified recovery Gmail, can reset its password through it,
 * and can change its own password. Other roles keep their existing workflows.
 */
class VpaaAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const GMAIL = 'vpaa.office@gmail.com';

    private User $vpaa;

    private User $dean;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $department = Departments::create(['department_code' => 'CCS', 'department_name' => 'College of Computer Studies']);
        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true, 'email' => null, 'password' => 'OldSecret123']);
        $this->vpaa->syncRoles(['vpaa']);
        $this->dean = User::factory()->create(['role' => 'dean', 'is_active' => true, 'department_id' => $department->id, 'password' => 'DeanSecret123']);
        $this->dean->syncRoles(['dean']);
    }

    public function test_vpaa_links_a_gmail_only_after_entering_the_emailed_code(): void
    {
        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/account/recovery-email', ['email' => 'VPAA.Office@Gmail.com'])
            ->assertOk()
            ->assertJsonPath('pending_email', self::GMAIL);

        $this->assertNull($this->vpaa->fresh()->email, 'The email must not be stored before verification.');

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/account/recovery-email/verify', ['code' => $this->sentCode()])
            ->assertOk()
            ->assertJsonPath('email', self::GMAIL);

        $this->assertSame(self::GMAIL, $this->vpaa->fresh()->email);
        $this->assertTrue(DB::table('authentication_audit_logs')->where('event', 'recovery_email_verified')->exists());
    }

    public function test_wrong_codes_are_rejected_and_the_code_is_burned_after_five_attempts(): void
    {
        $this->requestCode();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->vpaa, 'sanctum')
                ->postJson('/api/account/recovery-email/verify', ['code' => $wrong])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        }

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/account/recovery-email/verify', ['code' => $code])
            ->assertUnprocessable();
        $this->assertNull($this->vpaa->fresh()->email);
    }

    public function test_only_gmail_addresses_not_used_by_another_account_are_accepted(): void
    {
        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/account/recovery-email', ['email' => 'vpaa@yahoo.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->dean->forceFill(['email' => 'taken@gmail.com'])->save();
        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/account/recovery-email', ['email' => 'taken@gmail.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    public function test_code_requests_have_a_cooldown(): void
    {
        $this->requestCode();
        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/account/recovery-email', ['email' => self::GMAIL])
            ->assertStatus(429);
    }

    public function test_forgot_password_sends_a_reset_link_to_the_verified_gmail(): void
    {
        $this->linkGmail();

        $this->postJson('/api/forgot-password', ['email' => self::GMAIL])->assertOk();

        Notification::assertSentTo($this->vpaa->fresh(), ResetPasswordNotification::class);
    }

    public function test_changing_the_gmail_invalidates_reset_links_sent_to_the_old_one(): void
    {
        $this->linkGmail();
        $token = Password::createToken($this->vpaa->fresh());

        Cache::flush(); // clear the resend cooldown
        $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/account/recovery-email', ['email' => 'new.vpaa@gmail.com'])->assertOk();
        $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/account/recovery-email/verify', ['code' => $this->sentCode('new.vpaa@gmail.com')])->assertOk();

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => self::GMAIL,
            'password' => 'ResetSecret456',
            'password_confirmation' => 'ResetSecret456',
        ])->assertUnprocessable();
    }

    public function test_vpaa_changes_password_with_the_current_password(): void
    {
        $this->vpaa->forceFill(['email' => self::GMAIL])->save();
        $this->actingAs($this->vpaa, 'sanctum')->putJson('/api/account/password', [
            'current_password' => 'OldSecret123',
            'password' => 'NewSecret456',
            'password_confirmation' => 'NewSecret456',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecret456', $this->vpaa->fresh()->password));
        $this->assertTrue(DB::table('authentication_audit_logs')->where('event', 'password_changed')->exists());
    }

    public function test_password_change_keeps_the_current_session_and_signs_out_others(): void
    {
        $this->vpaa->forceFill(['email' => self::GMAIL])->save();
        $current = $this->vpaa->createToken('current')->plainTextToken;
        $this->vpaa->createToken('other-device');

        $this->withToken($current)->putJson('/api/account/password', [
            'current_password' => 'OldSecret123',
            'password' => 'NewSecret456',
            'password_confirmation' => 'NewSecret456',
        ])->assertOk();

        $this->assertSame(['current'], $this->vpaa->tokens()->pluck('name')->all());
    }

    public function test_password_change_validates_current_confirmation_strength_and_reuse(): void
    {
        $this->vpaa->forceFill(['email' => self::GMAIL])->save();
        $cases = [
            ['current_password' => 'WrongSecret1', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456', 'error' => 'current_password'],
            ['current_password' => 'OldSecret123', 'password' => 'NewSecret456', 'password_confirmation' => 'Mismatch4567', 'error' => 'password'],
            ['current_password' => 'OldSecret123', 'password' => 'weakpassword', 'password_confirmation' => 'weakpassword', 'error' => 'password'],
            ['current_password' => 'OldSecret123', 'password' => 'OldSecret123', 'password_confirmation' => 'OldSecret123', 'error' => 'password'],
        ];

        foreach ($cases as $case) {
            $error = $case['error'];
            unset($case['error']);
            $this->actingAs($this->vpaa, 'sanctum')->putJson('/api/account/password', $case)
                ->assertUnprocessable()->assertJsonValidationErrors($error);
        }

        $this->assertTrue(Hash::check('OldSecret123', $this->vpaa->fresh()->password));
    }

    public function test_password_change_is_blocked_until_a_recovery_gmail_is_verified(): void
    {
        $payload = ['current_password' => 'OldSecret123', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456'];

        $this->actingAs($this->vpaa, 'sanctum')->putJson('/api/account/password', $payload)
            ->assertStatus(409)->assertJsonPath('code', 'recovery_email_required');
        $this->assertTrue(Hash::check('OldSecret123', $this->vpaa->fresh()->password));

        // A code that was requested but not yet verified does not unlock it either.
        $this->requestCode();
        $this->actingAs($this->vpaa, 'sanctum')->putJson('/api/account/password', $payload)->assertStatus(409);

        $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/account/recovery-email/verify', ['code' => $this->sentCode()])->assertOk();
        $this->actingAs($this->vpaa->fresh(), 'sanctum')->putJson('/api/account/password', $payload)->assertOk();
    }

    public function test_other_roles_cannot_use_vpaa_account_security(): void
    {
        $this->actingAs($this->dean, 'sanctum')->postJson('/api/account/recovery-email', ['email' => self::GMAIL])->assertForbidden();
        $this->actingAs($this->dean, 'sanctum')->postJson('/api/account/recovery-email/verify', ['code' => '123456'])->assertForbidden();
        $this->actingAs($this->dean, 'sanctum')->putJson('/api/account/password', [
            'current_password' => 'DeanSecret123',
            'password' => 'NewSecret456',
            'password_confirmation' => 'NewSecret456',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('DeanSecret123', $this->dean->fresh()->password));
    }

    private function requestCode(string $email = self::GMAIL): void
    {
        $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/account/recovery-email', ['email' => $email])->assertOk();
    }

    private function linkGmail(): void
    {
        $this->requestCode();
        $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/account/recovery-email/verify', ['code' => $this->sentCode()])->assertOk();
    }

    private function sentCode(string $email = self::GMAIL): string
    {
        $code = null;
        Notification::assertSentOnDemand(
            RecoveryEmailVerificationNotification::class,
            function (RecoveryEmailVerificationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$code) {
                if (($notifiable->routes['mail'] ?? null) !== $email) {
                    return false;
                }
                $code = $notification->code;

                return true;
            },
        );

        return (string) $code;
    }
}
