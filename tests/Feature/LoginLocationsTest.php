<?php

namespace Tests\Feature;

use App\Models\AuthenticationAuditLog;
use App\Models\LoginGeolocation;
use App\Models\User;
use App\Services\IpGeolocationService;
use App\Support\UserAgentSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoginLocationsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ip_geolocation.enabled' => true, 'services.ip_geolocation.provider' => 'ipapi']);
        $this->providerResponse = Http::response([
            'city' => 'Zamboanga', 'region' => 'Zamboanga Peninsula', 'country_name' => 'Philippines',
            'country_code' => 'PH', 'latitude' => 6.910423, 'longitude' => 122.073891,
        ]);
        Http::fake(fn () => $this->providerResponse);
    }

    /** Swappable per test; Http::fake() stubs registered later would not override the first. */
    private mixed $providerResponse;

    private function login(string $username, string $password, string $ip = '203.0.113.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', self::CHROME_WINDOWS)
            ->postJson('/api/login', ['username' => $username, 'password' => $password]);
    }

    public function test_successful_login_records_an_approximate_location_on_the_existing_audit_event(): void
    {
        $user = User::factory()->create(['role' => 'secretary', 'password' => Hash::make('StrongPass123')]);

        $this->login($user->username, 'StrongPass123')->assertOk();

        $log = AuthenticationAuditLog::where('event', 'login_succeeded')->sole();
        $this->assertSame('203.0.113.7', $log->ip_address);
        $geo = $log->geolocation;
        $this->assertSame(LoginGeolocation::RESOLVED, $geo->lookup_status);
        $this->assertSame('Zamboanga', $geo->city);
        $this->assertSame('PH', $geo->country_code);
        // Rounded to ~1 km; precise coordinates are never stored.
        $this->assertSame(6.91, $geo->latitude);
        $this->assertSame(122.07, $geo->longitude);
    }

    public function test_failed_logins_are_recorded_without_storing_the_typed_username(): void
    {
        $user = User::factory()->create(['role' => 'dean', 'password' => Hash::make('StrongPass123')]);

        $this->login($user->username, 'wrong-password')->assertStatus(401);
        $this->login('Str0ngP@ss-typed-in-username', 'whatever')->assertStatus(401);

        $failures = AuthenticationAuditLog::where('event', 'login_failed')->orderBy('id')->get();
        $this->assertCount(2, $failures);
        $this->assertSame($user->id, $failures[0]->subject_user_id);
        $this->assertSame('invalid_password', $failures[0]->metadata['reason']);
        $this->assertNull($failures[1]->subject_user_id);
        $this->assertSame('unknown_account', $failures[1]->metadata['reason']);
        $this->assertStringNotContainsString('Str0ngP@ss', json_encode($failures[1]->metadata));
        $this->assertSame(2, LoginGeolocation::count());
    }

    public function test_disabled_account_attempt_is_recorded_as_failed(): void
    {
        $user = User::factory()->create(['is_active' => false, 'password' => Hash::make('StrongPass123')]);

        $this->login($user->username, 'StrongPass123')->assertStatus(403);

        $this->assertSame('account_disabled', AuthenticationAuditLog::where('event', 'login_failed')->sole()->metadata['reason']);
    }

    public function test_private_addresses_are_never_sent_to_the_provider(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);

        $this->login($user->username, 'StrongPass123', '192.168.1.20')->assertOk();

        $this->assertSame(LoginGeolocation::PRIVATE, LoginGeolocation::sole()->lookup_status);
        $this->assertNull(LoginGeolocation::sole()->latitude);
        Http::assertNothingSent();
    }

    public function test_provider_failure_never_blocks_sign_in(): void
    {
        $this->providerResponse = Http::response(['error' => true, 'reason' => 'RateLimited'], 429);
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);

        $this->login($user->username, 'StrongPass123')->assertOk()->assertJsonStructure(['token']);

        $this->assertSame(LoginGeolocation::UNAVAILABLE, LoginGeolocation::sole()->lookup_status);
    }

    public function test_null_island_and_disabled_lookups_resolve_to_unavailable(): void
    {
        $this->providerResponse = Http::response(['latitude' => 0, 'longitude' => 0, 'country_name' => 'Unknown']);
        $this->assertSame(LoginGeolocation::UNAVAILABLE, app(IpGeolocationService::class)->locate('198.51.100.4')['lookup_status']);

        config(['services.ip_geolocation.enabled' => false]);
        $this->assertSame(LoginGeolocation::UNAVAILABLE, app(IpGeolocationService::class)->locate('198.51.100.5')['lookup_status']);
    }

    public function test_ip_api_provider_flags_vpn_or_hosting_addresses(): void
    {
        config(['services.ip_geolocation.provider' => 'ip-api']);
        $this->providerResponse = Http::response([
            'status' => 'success', 'country' => 'Singapore', 'countryCode' => 'SG', 'regionName' => 'Central', 'city' => 'Singapore',
            'lat' => 1.29, 'lon' => 103.85, 'proxy' => false, 'hosting' => true,
        ]);

        $result = app(IpGeolocationService::class)->locate('198.51.100.9');

        $this->assertSame(LoginGeolocation::RESOLVED, $result['lookup_status']);
        $this->assertTrue($result['is_proxy']);
    }

    public function test_only_vpaa_can_read_login_locations_and_history(): void
    {
        foreach (['secretary', 'dean', 'director', 'program_head'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user, 'sanctum')->getJson('/api/activity-log/login-locations')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson('/api/activity-log/login-history')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/activity-log/login-locations')->assertUnauthorized();
    }

    public function test_vpaa_sees_map_points_and_history_with_device_details_and_filters(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $secretary = User::factory()->create(['role' => 'secretary', 'name' => 'Sam Secretary', 'password' => Hash::make('StrongPass123')]);
        $this->login($secretary->username, 'StrongPass123')->assertOk();
        $this->login($secretary->username, 'nope', '10.0.0.5')->assertStatus(401);
        $this->app['auth']->forgetGuards();

        $map = $this->actingAs($vpaa, 'sanctum')->getJson('/api/activity-log/login-locations')->assertOk();
        $map->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.located', 1)
            ->assertJsonPath('meta.summary.failed', 1)
            ->assertJsonPath('meta.retention_days', 90)
            ->assertJsonPath('data.0.user.name', 'Sam Secretary')
            ->assertJsonPath('data.0.user.role', 'secretary')
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.ip_address', '203.0.113.7')
            ->assertJsonPath('data.0.client.browser', 'Chrome 131')
            ->assertJsonPath('data.0.client.os', 'Windows 10/11')
            ->assertJsonPath('data.0.client.device', 'Desktop')
            ->assertJsonPath('data.0.location.city', 'Zamboanga');

        $history = $this->getJson('/api/activity-log/login-history')->assertOk();
        $history->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.reason', 'invalid_password')
            ->assertJsonPath('data.0.location.status', 'private');
        $this->assertContains($secretary->id, collect($history->json('meta.facets.users'))->pluck('id'));

        $this->getJson('/api/activity-log/login-history?status=failed')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/activity-log/login-history?role=dean')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/activity-log/login-history?role=secretary')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/activity-log/login-history?user_id='.$vpaa->id)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/activity-log/login-history?from=2000-01-01&to=2000-01-02')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/activity-log/login-history?role=invalid')->assertUnprocessable();
    }

    public function test_location_rows_are_pruned_after_retention_while_audit_events_remain(): void
    {
        config(['services.ip_geolocation.retention_days' => 30]);
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);
        $this->login($user->username, 'StrongPass123')->assertOk();

        $this->travel(31)->days();
        $this->artisan('model:prune', ['--model' => [LoginGeolocation::class]])->assertSuccessful();

        $this->assertSame(0, LoginGeolocation::count());
        $this->assertSame(1, AuthenticationAuditLog::where('event', 'login_succeeded')->count());

        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($vpaa, 'sanctum')->getJson('/api/activity-log/login-history')
            ->assertJsonPath('data.0.location.status', 'expired');
    }

    public function test_failed_logins_appear_in_the_existing_activity_log_as_failed(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $this->login('ghost-account', 'x')->assertStatus(401);
        $this->app['auth']->forgetGuards();

        $this->actingAs($vpaa, 'sanctum')->getJson('/api/activity-log?status=failed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'login_failed');
    }

    public function test_user_agent_summary_handles_common_clients(): void
    {
        $iphone = UserAgentSummary::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1');
        $this->assertSame(['browser' => 'Safari 17', 'os' => 'iOS 17', 'device' => 'Mobile'], $iphone);

        $edge = UserAgentSummary::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0');
        $this->assertSame('Edge 131', $edge['browser']);

        $this->assertSame('Unknown browser', UserAgentSummary::parse(null)['browser']);
    }

    public function test_vpaa_can_open_a_single_login_record_and_others_cannot(): void
    {
        $user = User::factory()->create(['role' => 'dean', 'name' => 'Dana Dean', 'password' => Hash::make('StrongPass123')]);
        $this->login($user->username, 'StrongPass123')->assertOk();
        $this->login($user->username, 'bad')->assertStatus(401);
        $success = AuthenticationAuditLog::where('event', 'login_succeeded')->sole();
        $failure = AuthenticationAuditLog::where('event', 'login_failed')->sole();
        $logout = AuthenticationAuditLog::create(['event' => 'logout', 'subject_user_id' => $user->id]);
        $this->app['auth']->forgetGuards();

        $this->actingAs($user, 'sanctum')->getJson("/api/activity-log/logins/{$success->id}")->assertForbidden();

        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $this->actingAs($vpaa, 'sanctum')->getJson("/api/activity-log/logins/{$success->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $success->id)
            ->assertJsonPath('data.user.name', 'Dana Dean')
            ->assertJsonPath('data.user.role', 'dean')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.client.browser', 'Chrome 131')
            ->assertJsonPath('data.location.city', 'Zamboanga')
            ->assertJsonPath('data.location.latitude', 6.91)
            ->assertJsonPath('meta.retention_days', 90);
        $this->getJson("/api/activity-log/logins/{$failure->id}")->assertOk()->assertJsonPath('data.status', 'failed');
        // Only sign-in attempts are exposed here, never other audit events.
        $this->getJson("/api/activity-log/logins/{$logout->id}")->assertNotFound();
        $this->getJson('/api/activity-log/logins/999999')->assertNotFound();
    }

    public function test_backfill_command_resolves_sign_ins_whose_lookup_never_completed(): void
    {
        $user = User::factory()->create();
        $missed = AuthenticationAuditLog::create(['event' => 'login_succeeded', 'subject_user_id' => $user->id, 'ip_address' => '203.0.113.7']);
        $local = AuthenticationAuditLog::create(['event' => 'login_failed', 'ip_address' => '127.0.0.1']);
        AuthenticationAuditLog::create(['event' => 'logout', 'subject_user_id' => $user->id, 'ip_address' => '203.0.113.7']);

        $this->artisan('logins:resolve-locations')->assertSuccessful();

        $this->assertSame(LoginGeolocation::RESOLVED, $missed->geolocation()->first()->lookup_status);
        $this->assertSame(LoginGeolocation::PRIVATE, $local->geolocation()->first()->lookup_status);
        $this->assertSame(2, LoginGeolocation::count());

        // Idempotent: a second run adds nothing.
        $this->artisan('logins:resolve-locations')->assertSuccessful();
        $this->assertSame(2, LoginGeolocation::count());
    }

    public function test_device_location_is_stored_for_success_and_failure_and_wins_over_the_ip_estimate(): void
    {
        $user = User::factory()->create(['role' => 'secretary', 'password' => Hash::make('StrongPass123')]);
        $device = ['latitude' => 6.9214367, 'longitude' => 122.0790212, 'accuracy' => 18.4, 'captured_at' => now()->toISOString()];

        // Private network: the IP cannot be mapped, the device fix still can.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/login', ['username' => $user->username, 'password' => 'bad', 'device_location' => $device])
            ->assertStatus(401);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/login', ['username' => $user->username, 'password' => 'StrongPass123', 'device_location' => $device])
            ->assertOk();

        [$failed, $succeeded] = LoginGeolocation::orderBy('id')->get()->all();
        $this->assertSame(LoginGeolocation::PRIVATE, $failed->lookup_status);
        $this->assertSame(6.92144, $failed->device_latitude);
        $this->assertSame(122.07902, $failed->device_longitude);
        $this->assertSame(18, $failed->device_accuracy_m);
        $this->assertSame(LoginGeolocation::SOURCE_DEVICE, $failed->source());
        // IP estimate is still resolved alongside the device fix.
        $this->assertSame('Zamboanga', $succeeded->city);
        // Device coordinates never enter the append-only audit trail.
        $this->assertStringNotContainsString('6.92', json_encode(AuthenticationAuditLog::pluck('metadata')));

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(['role' => 'vpaa']), 'sanctum')
            ->getJson("/api/activity-log/logins/{$failed->authentication_audit_log_id}")
            ->assertOk()
            ->assertJsonPath('data.location.source', 'device')
            ->assertJsonPath('data.location.device.accuracy_m', 18)
            ->assertJsonPath('data.location.device.latitude', 6.92144);
    }

    public function test_login_locations_map_includes_device_only_sign_ins_from_private_networks(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);
        $device = ['latitude' => 6.9214, 'longitude' => 122.079, 'accuracy' => 20, 'captured_at' => now()->toISOString()];
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/login', ['username' => $user->username, 'password' => 'StrongPass123', 'device_location' => $device])->assertOk();
        $this->login($user->username, 'StrongPass123', '127.0.0.1')->assertOk(); // neither source
        $this->app['auth']->forgetGuards();

        $this->actingAs(User::factory()->create(['role' => 'vpaa']), 'sanctum')
            ->getJson('/api/activity-log/login-locations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.located', 1)
            ->assertJsonPath('data.0.location.source', 'device')
            ->assertJsonPath('data.0.location.status', 'private');
    }

    public function test_invalid_or_stale_device_location_is_dropped_without_blocking_sign_in(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);
        $cases = [
            'not-an-object',
            ['latitude' => 91, 'longitude' => 10, 'accuracy' => 5],
            ['latitude' => 0, 'longitude' => 0, 'accuracy' => 5],
            ['latitude' => 6.9, 'longitude' => 122.0, 'accuracy' => -1],
            ['latitude' => 6.9, 'longitude' => 122.0, 'accuracy' => 500000],
            ['latitude' => 6.9, 'longitude' => 122.0, 'accuracy' => 5, 'captured_at' => now()->subHour()->toISOString()],
            ['latitude' => 'abc', 'longitude' => 122.0, 'accuracy' => 5],
        ];

        foreach ($cases as $device) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
                ->postJson('/api/login', ['username' => $user->username, 'password' => 'StrongPass123', 'device_location' => $device])->assertOk();
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame(count($cases), LoginGeolocation::count());
        $this->assertSame(0, LoginGeolocation::whereNotNull('device_latitude')->count());
        $this->assertSame(LoginGeolocation::SOURCE_IP, LoginGeolocation::first()->source());
    }

    public function test_source_is_none_when_neither_device_nor_ip_location_exists(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);
        $this->login($user->username, 'StrongPass123', '127.0.0.1')->assertOk();

        $this->assertSame(LoginGeolocation::SOURCE_NONE, LoginGeolocation::sole()->source());
    }
}
