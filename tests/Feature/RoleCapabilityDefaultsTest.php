<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Access follows from the role alone since Manage Access was removed, so the
 * role defaults are the whole authorization policy and have to be coherent on
 * their own.
 */
class RoleCapabilityDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_default_is_assignable_to_its_role_and_carries_its_prerequisites(): void
    {
        $registry = app(CapabilityRegistry::class);

        foreach ($registry->roleDefaults() as $role => $capabilities) {
            foreach ($capabilities as $capability) {
                $this->assertContains($capability, $registry->names(), "{$role} lists unknown capability {$capability}");
                $this->assertTrue($registry->isAssignableToRole($role, $capability), "{$role} may not hold {$capability}");
            }

            $this->assertEqualsCanonicalizing(
                $capabilities,
                $registry->expand($capabilities),
                "{$role} is missing a prerequisite of one of its capabilities",
            );
        }
    }

    public function test_secretaries_and_program_heads_share_one_set_of_capabilities(): void
    {
        $defaults = app(CapabilityRegistry::class)->roleDefaults();

        $this->assertEqualsCanonicalizing($defaults['secretary'], $defaults['program_head']);
        $this->assertContains('schedule.create', $defaults['secretary']);
        $this->assertNotContains('schedule.approve_dean', $defaults['secretary']);
        $this->assertNotContains('schedule.approve_vpaa', $defaults['secretary']);
        $this->assertNotContains('room.review_requests', $defaults['secretary']);
        $this->assertNotContains('faculty.manage_designations', $defaults['secretary']);
    }

    public function test_a_newly_created_secretary_can_work_on_schedules_without_any_grant(): void
    {
        $department = Departments::create(['department_code' => 'CCS', 'department_name' => 'College of Computer Studies']);
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology']);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true]);
        Sanctum::actingAs($vpaa);

        $id = $this->postJson('/api/user', [
            'first_name' => 'Sam',
            'last_name' => 'Secretary',
            'username' => 'sam.secretary',
            'email' => 'sam.secretary@example.test',
            'password' => 'CorrectHorse42',
            'role' => 'secretary',
            'department_id' => $department->id,
            'faculty_mode' => 'none',
        ])->assertCreated()->json('data.id');

        $secretary = User::findOrFail($id);
        $this->assertTrue($secretary->hasCapability('schedule.create'));
        $this->assertTrue($secretary->hasCapability('schedule.submit'));
        $this->assertFalse($secretary->hasCapability('schedule.approve_vpaa'));
        $this->assertSame([], $secretary->getDirectPermissions()->pluck('name')->all());
    }

    public function test_the_per_account_permission_endpoints_are_gone(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true]);
        $dean = User::factory()->create(['role' => 'dean', 'is_active' => true]);
        Sanctum::actingAs($vpaa);

        $this->getJson("/api/user/{$dean->id}/permissions")->assertNotFound();
        $this->patchJson("/api/user/{$dean->id}/permissions", ['permissions' => []])->assertNotFound();
    }

    public function test_the_migration_replaces_direct_grants_with_role_defaults(): void
    {
        $department = Departments::create(['department_code' => 'CCS', 'department_name' => 'College of Computer Studies']);
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $dean->givePermissionTo('schedule.create');

        $migration = require database_path('migrations/2026_09_15_000001_replace_per_account_grants_with_role_defaults.php');
        $migration->up();

        $this->assertSame([], $dean->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->assertFalse($dean->fresh()->hasCapability('schedule.create'));
        $this->assertTrue($dean->fresh()->hasCapability('schedule.approve_dean'));
        $this->assertTrue($secretary->fresh()->hasCapability('schedule.generate'));
    }
}
