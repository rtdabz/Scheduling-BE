<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_with_username_or_email(): void
    {
        $user = User::factory()->create([
            'username' => 'secretary1',
            'email' => 'secretary1@school.edu.ph',
            'password' => Hash::make('StrongPass123'),
        ]);

        $this->postJson('/api/login', ['username' => 'SECRETARY1', 'password' => 'StrongPass123'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/login', ['username' => 'secretary1@school.edu.ph', 'password' => 'StrongPass123'])
            ->assertOk();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['is_active' => false, 'password' => Hash::make('StrongPass123')]);

        $this->postJson('/api/login', ['username' => User::first()->username, 'password' => 'StrongPass123'])
            ->assertStatus(403);
    }

    public function test_vpaa_can_create_and_disable_a_google_enabled_user(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $departmentId = DB::table('departments')->insertGetId([
            'department_name' => 'Test Department',
            'department_code' => 'TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($vpaa, 'sanctum')->postJson('/api/user', [
            'name' => 'Approved User',
            'username' => 'approved.user',
            'email' => 'approved@school.edu.ph',
            'password' => 'StrongPass123',
            'role' => 'secretary',
            'department_id' => $departmentId,
            'allow_google_login' => true,
        ])->assertCreated();

        $user = User::where('username', 'approved.user')->firstOrFail();
        $this->actingAs($vpaa, 'sanctum')->putJson("/api/user/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'department_id' => $departmentId,
            'is_active' => false,
            'allow_google_login' => true,
        ])->assertOk();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('faculties', [
            'user_id' => $user->id,
            'administrative_role' => 'secretary',
            'department_id' => $departmentId,
        ]);
        $this->assertDatabaseHas('authentication_audit_logs', ['event' => 'user_created', 'subject_user_id' => $user->id]);
        $response->assertJsonPath('data.allow_google_login', true);

        $facultyId = Faculty::where('user_id', $user->id)->value('id');
        $this->actingAs($vpaa, 'sanctum')->deleteJson("/api/user/{$user->id}")
            ->assertOk();
        $this->assertDatabaseHas('faculties', [
            'id' => $facultyId,
            'user_id' => null,
            'administrative_role' => null,
        ]);
    }

    public function test_google_login_links_the_preapproved_email_and_uses_one_time_exchange(): void
    {
        config()->set('services.google.client_id', 'client-id');
        config()->set('services.google.client_secret', 'client-secret');
        config()->set('services.google.redirect', 'http://localhost/api/auth/google/callback');
        config()->set('services.frontend_url', 'http://localhost:5173');

        $user = User::factory()->create([
            'email' => 'approved@school.edu.ph',
            'allow_google_login' => true,
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-1', 'email' => 'approved@school.edu.ph', 'email_verified' => true,
            ]),
        ]);

        $redirect = $this->getJson('/api/auth/google/redirect')->assertOk();
        parse_str(parse_url($redirect->json('url'), PHP_URL_QUERY), $query);
        $callback = $this->get('/api/auth/google/callback?'.http_build_query(['code' => 'oauth-code', 'state' => $query['state']]));
        $callback->assertRedirect();
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $result);

        $exchange = $this->postJson('/api/auth/google/exchange', [
            'code' => $result['google_code'],
            'state' => $result['google_state'],
        ])->assertOk();
        $exchange->assertJsonPath('user.email', 'approved@school.edu.ph');
        $this->assertSame('google-sub-1', $user->fresh()->google_id);
        $this->postJson('/api/auth/google/exchange', [
            'code' => $result['google_code'],
            'state' => $result['google_state'],
        ])->assertUnauthorized();
    }

    public function test_forgot_password_has_generic_response(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'reset@school.edu.ph']);

        $this->postJson('/api/forgot-password', ['email' => 'reset@school.edu.ph'])
            ->assertOk()
            ->assertJsonPath('message', 'If an active account matches that email, a password reset link has been sent.');

        Notification::assertSentTo(User::first(), ResetPasswordNotification::class);
    }

}
