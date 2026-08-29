<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScheduleSetting;
use App\Models\User;
use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeslotSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_vpaa_can_extend_the_institutional_closing_time(): void
    {
        $user = User::factory()->create(['role' => 'vpaa']);

        $response = $this->actingAs($user)->patchJson('/api/timeslots/settings', [
            'opening_time' => '7:00 AM',
            'closing_time' => '8:00 PM',
            'slot_interval' => 30,
        ]);

        $response->assertOk()
            ->assertJsonPath('settings.opening_time', '7:00 AM')
            ->assertJsonPath('settings.closing_time', '8:00 PM')
            ->assertJsonPath('settings.slot_interval', 30);

        $this->assertDatabaseHas('schedule_settings', [
            'opening_time' => '07:00:00',
            'closing_time' => '20:00:00',
            'slot_interval' => 30,
        ]);
        $this->assertSame('20:00:00', SchedulingPolicy::closingTime());
    }

    public function test_department_secretary_cannot_change_institutional_operating_hours(): void
    {
        ScheduleSetting::query()->create([
            'opening_time' => '07:00:00',
            'closing_time' => '19:00:00',
            'slot_interval' => 30,
        ]);
        $user = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($user)->patchJson('/api/timeslots/settings', [
            'opening_time' => '7:00 AM',
            'closing_time' => '8:00 PM',
            'slot_interval' => 30,
        ])->assertForbidden();

        $this->assertDatabaseHas('schedule_settings', [
            'closing_time' => '19:00:00',
        ]);
    }
}
