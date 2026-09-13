<?php

namespace Tests\Feature;

use App\Models\AuthenticationAuditLog;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private Program $program;

    private User $vpaa;

    private User $dean;

    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->department = Departments::create([
            'department_code' => 'CCS',
            'department_name' => 'College of Computer Studies',
        ]);

        $this->program = Program::create([
            'department_id' => $this->department->id,
            'code' => 'BSIT',
            'name' => 'Bachelor of Science in Information Technology',
        ]);

        $this->vpaa = User::factory()->create([
            'role' => 'vpaa',
            'is_active' => true,
        ]);
        $this->vpaa->syncRoles(['vpaa']);

        $this->dean = User::factory()->create([
            'role' => 'dean',
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->dean->syncRoles(['dean']);

        $this->secretary = User::factory()->create([
            'role' => 'secretary',
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->secretary->syncRoles(['secretary']);
    }

    public function test_vpaa_can_view_user_permissions_breakdown(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        // Dean role inherently has schedule.view and schedule.approve_dean
        $response = $this->getJson("/api/user/{$this->dean->id}/permissions");

        $response->assertOk()
            ->assertJsonStructure([
                'user_id',
                'inherited',
                'direct',
                'effective',
                'catalog',
                'catalog_metadata',
                'modules',
                'presets',
            ]);

        $data = $response->json();
        $this->assertEquals($this->dean->id, $data['user_id']);
        $this->assertContains('schedule.view', $data['inherited']);
        $this->assertContains('schedule.approve_dean', $data['inherited']);
        $this->assertEmpty($data['direct']);
        $this->assertContains('schedule.view', $data['effective']);
        $this->assertContains('schedule.create', $data['catalog']);
        $this->assertTrue(collect($data['catalog_metadata'])->firstWhere('id', 'schedule.create')['assignable']);
        $this->assertContains('schedule_workspace', collect($data['modules'])->pluck('id')->all());
    }

    public function test_non_vpaa_cannot_view_or_update_permissions(): void
    {
        Sanctum::actingAs($this->dean, ['*']);

        $this->getJson("/api/user/{$this->secretary->id}/permissions")
            ->assertForbidden();

        $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['schedule.view'],
        ])->assertForbidden();
    }

    public function test_vpaa_can_update_direct_permissions(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $response = $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['schedule.view', 'schedule.assign_instructor'],
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'User permissions updated successfully.',
                'data' => [
                    'user_id' => $this->secretary->id,
                    'direct' => ['schedule.assign_instructor', 'schedule.view'],
                    'effective' => ['schedule.assign_instructor', 'schedule.view'],
                ],
            ]);

        $this->assertTrue($this->secretary->fresh()->hasDirectPermission('schedule.view'));
        $this->assertTrue($this->secretary->fresh()->hasDirectPermission('schedule.assign_instructor'));
        $this->assertFalse($this->secretary->fresh()->hasDirectPermission('schedule.create'));
    }

    /**
     * A secretary granted instructor assignment alone could open the
     * Cross-Department page and the dashboard, then watch both fail: every
     * endpoint behind them is guarded by `schedule.view`.
     */
    public function test_granting_a_capability_pulls_in_its_prerequisites(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $response = $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['schedule.assign_instructor'],
        ]);

        $response->assertOk();
        $this->assertTrue($this->secretary->fresh()->hasDirectPermission('schedule.view'));

        Sanctum::actingAs($this->secretary->fresh(), ['*']);
        $this->getJson("/api/departments/{$this->department->id}/schedule-status")->assertOk();
    }

    public function test_a_prerequisite_cannot_be_dropped_while_a_dependent_is_kept(): void
    {
        $this->secretary->syncPermissions(['schedule.view', 'schedule.assign_instructor']);

        Sanctum::actingAs($this->vpaa, ['*']);

        $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['schedule.assign_instructor'],
        ])->assertOk();

        $this->assertTrue($this->secretary->fresh()->hasDirectPermission('schedule.view'));
    }

    public function test_revoking_the_dependent_releases_the_prerequisite(): void
    {
        $this->secretary->syncPermissions(['schedule.view', 'schedule.assign_instructor']);

        Sanctum::actingAs($this->vpaa, ['*']);

        $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => [],
        ])->assertOk();

        $this->assertSame([], $this->secretary->fresh()->getDirectPermissions()->pluck('name')->all());
    }
    public function test_invalid_permission_names_are_rejected(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $response = $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['non_existent_capability'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_role_incompatible_approval_permissions_are_rejected(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['schedule.approve_dean'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);

        $this->patchJson("/api/user/{$this->dean->id}/permissions", [
            'permissions' => ['schedule.approve_vpaa'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_user_create_rejects_role_incompatible_permissions(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $this->postJson('/api/user', [
            'first_name' => 'Invalid',
            'last_name' => 'Approver',
            'username' => 'invalid.approver',
            'email' => 'invalid.approver@school.edu.ph',
            'password' => 'Password123!',
            'role' => 'secretary',
            'department_id' => $this->department->id,
            'permissions' => ['schedule.approve_dean'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_role_inherited_permissions_are_not_cleared_by_direct_sync(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        // Dean role inherits schedule.view & schedule.approve_dean.
        // Grant Dean direct schedule.create
        $this->patchJson("/api/user/{$this->dean->id}/permissions", [
            'permissions' => ['schedule.create'],
        ])->assertOk();

        $freshDean = $this->dean->fresh();
        // Has direct schedule.create
        $this->assertTrue($freshDean->hasDirectPermission('schedule.create'));
        // Inherited are still effective
        $this->assertTrue($freshDean->hasCapability('schedule.view'));
        $this->assertTrue($freshDean->hasCapability('schedule.approve_dean'));
        $this->assertTrue($freshDean->hasCapability('schedule.create'));

        // Now clear all direct permissions
        $this->patchJson("/api/user/{$this->dean->id}/permissions", [
            'permissions' => [],
        ])->assertOk();

        $freshDean = $this->dean->fresh();
        $this->assertFalse($freshDean->hasDirectPermission('schedule.create'));
        // Role permissions still remain effective!
        $this->assertTrue($freshDean->hasCapability('schedule.view'));
        $this->assertTrue($freshDean->hasCapability('schedule.approve_dean'));
    }

    public function test_permission_changes_are_recorded_in_audit_log(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['schedule.view', 'schedule.create'],
        ])->assertOk();

        $audit = AuthenticationAuditLog::query()
            ->where('event', 'user_updated')
            ->where('subject_user_id', $this->secretary->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('permissions_updated', $audit->metadata['action'] ?? null);
        $this->assertEquals(['schedule.view', 'schedule.create'], $audit->metadata['permission_changes']['added'] ?? []);
        $this->assertEmpty($audit->metadata['permission_changes']['removed'] ?? []);

        // Now remove schedule.create and add schedule.delete
        $this->patchJson("/api/user/{$this->secretary->id}/permissions", [
            'permissions' => ['schedule.view', 'schedule.delete'],
        ])->assertOk();

        $audit2 = AuthenticationAuditLog::query()
            ->where('event', 'user_updated')
            ->where('subject_user_id', $this->secretary->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit2);
        $this->assertEquals(['schedule.delete'], $audit2->metadata['permission_changes']['added'] ?? []);
        $this->assertEquals(['schedule.create'], $audit2->metadata['permission_changes']['removed'] ?? []);
    }

    public function test_vpaa_cannot_modify_vpaa_permissions(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $this->patchJson("/api/user/{$this->vpaa->id}/permissions", [
            'permissions' => ['schedule.view'],
        ])->assertStatus(403);
    }

    public function test_create_user_defaults_to_clean_role_permissions_without_direct_grants(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $response = $this->postJson('/api/user', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'username' => 'msantos',
            'email' => 'msantos@school.edu.ph',
            'password' => 'Password123!',
            'role' => 'secretary',
            'department_id' => $this->department->id,
        ]);

        $response->assertStatus(201);
        $createdUser = User::where('email', 'msantos@school.edu.ph')->first();
        $this->assertNotNull($createdUser);
        $this->assertEmpty($createdUser->getDirectPermissions());
        $this->assertTrue($createdUser->hasRole('secretary'));
    }

    public function test_vpaa_can_list_users_with_permissions(): void
    {
        Sanctum::actingAs($this->vpaa, ['*']);

        $response = $this->getJson('/api/user');
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertNotEmpty($json);

        $firstUser = $json[0];
        $this->assertArrayHasKey('permissions', $firstUser);
        $this->assertArrayHasKey('direct_permissions', $firstUser);
        $this->assertArrayHasKey('inherited_permissions', $firstUser);
    }
}
