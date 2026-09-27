<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InstitutionSetting;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
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

        $this->assertDatabaseHas('institution_settings', [
            'opening_time' => '07:00:00',
            'closing_time' => '20:00:00',
            'slot_interval' => 30,
        ]);
        $this->assertSame('20:00:00', SchedulingPolicy::closingTime());
    }

    public function test_department_secretary_cannot_change_institutional_operating_hours(): void
    {
        InstitutionSetting::current()->update([
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

        $this->assertDatabaseHas('institution_settings', [
            'closing_time' => '19:00:00',
        ]);
    }

    public function test_operating_hours_share_the_row_with_the_signatory(): void
    {
        InstitutionSetting::current()->update(['president_name' => 'Dr. Jane Doe']);
        $user = User::factory()->create(['role' => 'vpaa']);

        $this->actingAs($user)->patchJson('/api/timeslots/settings', [
            'opening_time' => '7:30 AM',
            'closing_time' => '6:00 PM',
            'slot_interval' => 30,
        ])->assertOk();

        $this->assertSame(1, InstitutionSetting::query()->count());
        $this->assertDatabaseHas('institution_settings', [
            'president_name' => 'Dr. Jane Doe',
            'opening_time' => '07:30:00',
            'closing_time' => '18:00:00',
        ]);
        // The signatory endpoint still returns only the signatory.
        $this->actingAs($user)->getJson('/api/institution-settings')
            ->assertOk()
            ->assertJsonPath('president_name', 'Dr. Jane Doe')
            ->assertJsonMissingPath('opening_time');
    }
}
