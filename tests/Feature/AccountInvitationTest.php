<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function createAccount(): array
    {
        Notification::fake();
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $departmentId = DB::table('departments')->insertGetId([
            'department_name' => 'Test Department',
            'department_code' => 'TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($vpaa, 'sanctum')->postJson('/api/user', [
            'first_name' => 'Invited',
            'last_name' => 'User',
            'username' => 'testsecretary',
            'email' => 'invited@school.edu.ph',
            'role' => 'secretary',
            'department_id' => $departmentId,
        ])->assertCreated();

        $user = User::where('username', 'testsecretary')->firstOrFail();
        $token = null;
        Notification::assertSentTo($user, AccountInvitationNotification::class, function ($notification) use (&$token, $user) {
            parse_str((string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY), $query);
            $token = $query['reset_token'] ?? null;

            return ($query['invite'] ?? null) === '1';
        });

        return [$vpaa, $user, $token, $response];
    }

    private function acceptInvite(string $token, string $password = 'MyOwnPass123')
    {
        return $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'invited@school.edu.ph',
            'password' => $password,
            'password_confirmation' => $password,
            'invite' => true,
        ]);
    }

    public function test_created_account_receives_setup_link_and_no_password_is_exposed(): void
    {
        [, $user, $token, $response] = $this->createAccount();

        $this->assertNotNull($token);
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertDatabaseHas('authentication_audit_logs', ['event' => 'invitation_sent', 'subject_user_id' => $user->id]);

        // No password works before the user sets one.
        $this->postJson('/api/login', ['username' => 'testsecretary', 'password' => 'StrongPass123'])->assertUnauthorized();
    }

    public function test_user_sets_password_through_link_and_can_then_sign_in(): void
    {
        [, $user, $token] = $this->createAccount();

        $this->acceptInvite($token)->assertOk();

        $this->postJson('/api/login', ['username' => 'testsecretary', 'password' => 'MyOwnPass123'])->assertOk();
        $this->assertDatabaseHas('authentication_audit_logs', ['event' => 'invitation_accepted', 'subject_user_id' => $user->id]);
    }

    public function test_setup_link_works_only_once(): void
    {
        [, , $token] = $this->createAccount();

        $this->acceptInvite($token)->assertOk();
        $this->acceptInvite($token, 'AnotherPass456')->assertStatus(422);

        $this->postJson('/api/login', ['username' => 'testsecretary', 'password' => 'AnotherPass456'])->assertUnauthorized();
    }

    public function test_expired_setup_link_is_rejected(): void
    {
        [, , $token] = $this->createAccount();

        $this->travel((int) config('auth.passwords.invites.expire') + 1)->minutes();

        $this->acceptInvite($token)->assertStatus(422);
    }

    public function test_setup_link_is_not_accepted_as_a_reset_token_and_vice_versa(): void
    {
        [, $user, $token] = $this->createAccount();

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'invited@school.edu.ph',
            'password' => 'MyOwnPass123',
            'password_confirmation' => 'MyOwnPass123',
        ])->assertStatus(422);

        $resetToken = Password::createToken($user);
        $this->acceptInvite($resetToken)->assertStatus(422);
    }

    public function test_vpaa_can_resend_setup_link_which_replaces_the_old_one(): void
    {
        [$vpaa, $user, $oldToken] = $this->createAccount();

        // The broker throttles repeat links for the same account.
        $this->actingAs($vpaa, 'sanctum')->postJson("/api/user/{$user->id}/invitation")->assertStatus(429);

        $this->travel(2)->minutes();
        Notification::fake();
        $this->actingAs($vpaa, 'sanctum')->postJson("/api/user/{$user->id}/invitation")->assertOk();
        Notification::assertSentTo($user, AccountInvitationNotification::class);

        $this->acceptInvite($oldToken)->assertStatus(422);
    }

    public function test_setup_link_cannot_be_sent_by_non_vpaa_or_to_inactive_accounts(): void
    {
        [$vpaa, $user] = $this->createAccount();
        $this->travel(2)->minutes();

        $this->actingAs($user, 'sanctum')->postJson("/api/user/{$user->id}/invitation")->assertForbidden();

        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($vpaa, 'sanctum')->postJson("/api/user/{$user->id}/invitation")->assertStatus(422);
    }
}
