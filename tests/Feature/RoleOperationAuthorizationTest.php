<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleOperationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dean_cannot_use_room_write_routes(): void
    {
        $dean = User::factory()->create(['role' => 'dean']);

        $this->actingAs($dean)->postJson('/api/rooms', [
            'room_code' => 'A-101',
            'room_type' => 'lecture',
            'status' => 'available',
        ])->assertForbidden();
    }

    public function test_dean_cannot_mutate_schedules(): void
    {
        $dean = User::factory()->create(['role' => 'dean']);

        $this->actingAs($dean)->postJson('/api/schedules/batch', [
            'operations' => [],
        ])->assertForbidden();
    }

    public function test_dean_cannot_submit_department_schedules(): void
    {
        $dean = User::factory()->create(['role' => 'dean']);

        $this->actingAs($dean)->postJson('/api/departments/1/submit-schedules')
            ->assertForbidden();
    }

    public function test_dean_cannot_use_curriculum_write_routes(): void
    {
        $dean = User::factory()->create(['role' => 'dean']);

        $this->actingAs($dean)->postJson('/api/curriculum', [
            'name' => 'Unauthorized Curriculum',
        ])->assertForbidden();
    }

    public function test_dean_cannot_use_section_write_routes(): void
    {
        $dean = User::factory()->create(['role' => 'dean']);

        $this->actingAs($dean)->postJson('/api/sections', [
            'section_name' => 'Unauthorized Section',
        ])->assertForbidden();
    }
}
