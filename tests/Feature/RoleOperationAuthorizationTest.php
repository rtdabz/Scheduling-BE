<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleOperationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_roles_can_view_only_their_own_department(): void
    {
        $own = Departments::create(['department_name' => 'Own Department', 'department_code' => 'OWN']);
        $other = Departments::create(['department_name' => 'Other Department', 'department_code' => 'OTH']);

        foreach (['dean', 'secretary', 'program_head'] as $role) {
            $user = User::factory()->create(['role' => $role, 'department_id' => $own->id]);

            $this->actingAs($user->fresh())->getJson("/api/departments/{$own->id}")->assertOk();
            $this->actingAs($user->fresh())->getJson("/api/departments/{$other->id}")->assertForbidden();
        }

        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $this->actingAs($vpaa->fresh())->getJson("/api/departments/{$other->id}")->assertOk();
    }

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

    /**
     * Curriculum authoring moved from the VPAA to the department secretary, so
     * the VPAA now reads curricula without being able to change them. It is the
     * one capability the break-glass account does not hold, which makes it worth
     * pinning: a future re-sync that hands the VPAA everything would silently
     * undo the split.
     */
    public function test_vpaa_cannot_use_curriculum_write_routes(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $vpaa->assignRole('vpaa');

        $this->actingAs($vpaa->fresh())->postJson('/api/curriculum', [
            'name' => 'Curriculum The VPAA May Not Author',
            'code' => 'VPAA-NO-WRITE',
            'effective_school_year' => '2026-2027',
        ])->assertForbidden();
    }

    /** The other half of the swap: the secretary's role alone carries the grant. */
    public function test_secretary_role_carries_curriculum_management(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $secretary->assignRole('secretary');

        $this->assertTrue($secretary->fresh()->hasCapability('curriculum.manage'));
    }
}
