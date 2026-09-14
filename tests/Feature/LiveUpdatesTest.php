<?php

namespace Tests\Feature;

use App\Events\LiveDataChanged;
use App\Events\UserNotificationsChanged;
use App\Models\Designation;
use App\Models\SystemNotification;
use App\Models\User;
use App\Support\ApiCache;
use App\Support\LiveUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Real-time updates: writes record topic names, and one small broadcast goes
 * out when the request or job finishes. Broadcasting must never break a write.
 */
class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml uses the null broadcaster, which LiveUpdates skips.
        config(['broadcasting.default' => 'reverb']);
        Cache::forget('live-updates:unreachable');
    }

    public function test_model_writes_are_coalesced_into_one_broadcast(): void
    {
        Event::fake([LiveDataChanged::class]);

        Designation::create(['name' => 'Dean', 'deload_units' => 6]);
        Designation::create(['name' => 'Chair', 'deload_units' => 3]);
        ApiCache::forgetGroup('rooms.index');

        app(LiveUpdates::class)->flush('1234.5678');

        Event::assertDispatchedTimes(LiveDataChanged::class, 1);
        Event::assertDispatched(LiveDataChanged::class, function (LiveDataChanged $event): bool {
            sort($event->topics);

            return $event->topics === ['faculty', 'rooms']
                && $event->socket === '1234.5678'
                && $event->broadcastWith() === ['topics' => $event->topics];
        });
    }

    public function test_nothing_is_broadcast_when_nothing_changed(): void
    {
        Event::fake([LiveDataChanged::class, UserNotificationsChanged::class]);

        app(LiveUpdates::class)->flush();

        Event::assertNotDispatched(LiveDataChanged::class);
        Event::assertNotDispatched(UserNotificationsChanged::class);
    }

    public function test_a_sign_in_timestamp_alone_is_not_announced(): void
    {
        Event::fake([LiveDataChanged::class]);
        $user = User::factory()->create();
        app(LiveUpdates::class)->flush();

        $user->forceFill(['last_login_at' => now()])->save();
        app(LiveUpdates::class)->flush();

        // Only the account creation itself was announced.
        Event::assertDispatchedTimes(LiveDataChanged::class, 1);
    }

    public function test_new_notifications_ping_only_their_recipient(): void
    {
        Event::fake([LiveDataChanged::class, UserNotificationsChanged::class]);
        $user = User::factory()->create();
        app(LiveUpdates::class)->flush();

        SystemNotification::create([
            'user_id' => $user->id,
            'type' => 'schedule_approved_by_vpaa',
            'title' => 'Approved',
            'message' => 'Approved.',
        ]);
        app(LiveUpdates::class)->flush();

        Event::assertDispatched(
            UserNotificationsChanged::class,
            fn (UserNotificationsChanged $event): bool => $event->userId === $user->id
                && $event->broadcastOn()->name === 'private-App.Models.User.'.$user->id,
        );
    }

    public function test_an_unreachable_websocket_server_does_not_fail_the_write(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        app(LiveUpdates::class)->touch('schedules');
        app(LiveUpdates::class)->flush();

        $this->assertTrue(Cache::has('live-updates:unreachable'));
        $this->assertFalse(app(LiveUpdates::class)->hasPending());
    }

    public function test_channel_auth_requires_a_signed_in_user(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-wicars.live',
        ])->assertUnauthorized();
    }

    public function test_a_user_may_join_the_live_channel_and_only_their_own_bell(): void
    {
        $this->useReverbCredentials();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-wicars.live',
        ])->assertOk()->assertJsonStructure(['auth']);

        $this->actingAs($user)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$user->id,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$other->id,
        ])->assertForbidden();
    }

    public function test_realtime_config_exposes_the_key_but_never_the_secret(): void
    {
        $this->useReverbCredentials();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/realtime-config')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('key', 'test-key');

        $this->assertStringNotContainsString('test-secret', $response->getContent());
    }

    private function useReverbCredentials(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        // Channels were registered on the test's null broadcaster at boot;
        // rebuild the Reverb one with these credentials and register them there.
        app(\Illuminate\Broadcasting\BroadcastManager::class)->purge('reverb');
        require base_path('routes/channels.php');
    }
}
