<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Designations: the CRUD for the administrative posts an instructor may hold,
 * and the deload each one takes off their Basic Load.
 *
 * The list is data, not an enum -- nothing here names a fixed set of
 * designations, and neither does the application.
 */
class DesignationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_vpaa_creates_a_designation(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['vpaa'])
            ->postJson('/api/designations', [
                'name' => 'Dean',
                'code' => 'dn',
                'deload_units' => 6,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dean')
            ->assertJsonPath('data.code', 'DN')
            ->assertJsonPath('data.deload_units', 6)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_designation_names_are_unique(): void
    {
        $f = $this->fixture();
        Designation::create(['name' => 'Dean', 'deload_units' => 6]);

        $this->actingAs($f['vpaa'])
            ->postJson('/api/designations', ['name' => 'Dean', 'deload_units' => 3])
            ->assertStatus(422);
    }

    /**
     * The whole point of the feature: a 21-unit instructor given a designation
     * worth 6 units of deload has a 15-unit Basic Load.
     */
    public function test_assigning_a_designation_deloads_the_instructor(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['designation_id' => $dean->id])
            ->assertOk()
            ->assertJsonPath('deload_units', 6)
            ->assertJsonPath('max_units', 21)
            ->assertJsonPath('required_units', 15);

        $this->assertSame(6, (int) $f['faculty']->fresh()->deload_units);
    }

    public function test_clearing_a_designation_releases_the_deload(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);
        $f['faculty']->update(['designation_id' => $dean->id, 'deload_units' => 6]);

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['designation_id' => null])
            ->assertOk()
            ->assertJsonPath('deload_units', 0)
            ->assertJsonPath('required_units', 21);
    }

    /**
     * The two fields have disjoint gates -- the designation belongs to the
     * VPAA, the load allowances to the Secretary -- so no single caller can
     * pair a designation with a deload of their own choosing. The request is
     * refused whole rather than applying the half the caller is entitled to.
     */
    public function test_request_deload_cannot_override_the_designation(): void
    {
        $f = $this->fixture();
        $chair = Designation::create(['name' => 'Program Chairperson', 'deload_units' => 3]);

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/faculties/{$f['faculty']->id}", [
                'designation_id' => $chair->id,
                'deload_units' => 18,
            ])
            ->assertStatus(403);

        $faculty = $f['faculty']->fresh();
        $this->assertNull($faculty->designation_id);
        $this->assertSame(0, (int) $faculty->deload_units);

        // Assigned on its own, the deload still comes from the designation row.
        $this->actingAs($f['vpaa'])
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['designation_id' => $chair->id])
            ->assertOk()
            ->assertJsonPath('deload_units', 3);
    }

    public function test_creating_an_instructor_with_a_designation_carries_its_deload(): void
    {
        $f = $this->fixture();
        $head = Designation::create(['name' => 'Laboratory Head', 'deload_units' => 4]);

        $this->actingAs($f['vpaa'])
            ->postJson('/api/faculties', [
                'first_name' => 'New',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'max_units' => 21,
                'department_id' => $f['department']->id,
                'designation_id' => $head->id,
            ])
            ->assertCreated()
            ->assertJsonPath('deload_units', 4)
            ->assertJsonPath('required_units', 17);
    }

    /**
     * The deload is copied onto each instructor so the scheduler reads one
     * column, so editing the designation has to rewrite its holders -- otherwise
     * the figure on screen stops matching the Basic Load actually enforced.
     */
    public function test_editing_the_deload_rewrites_current_holders(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);
        $f['faculty']->update(['designation_id' => $dean->id, 'deload_units' => 6]);

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/designations/{$dean->id}", ['deload_units' => 9])
            ->assertOk()
            ->assertJsonPath('holders_updated', 1);

        $this->assertSame(9, (int) $f['faculty']->fresh()->deload_units);
    }

    public function test_renaming_a_designation_leaves_holder_loads_alone(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);
        $f['faculty']->update(['designation_id' => $dean->id, 'deload_units' => 4]);

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/designations/{$dean->id}", ['name' => 'College Dean'])
            ->assertOk()
            ->assertJsonPath('holders_updated', 0);

        $this->assertSame(4, (int) $f['faculty']->fresh()->deload_units);
    }

    public function test_a_designation_with_holders_cannot_be_archived(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);
        $f['faculty']->update(['designation_id' => $dean->id, 'deload_units' => 6]);

        $this->actingAs($f['vpaa'])
            ->deleteJson("/api/designations/{$dean->id}")
            ->assertStatus(409)
            ->assertJsonPath('holders_count', 1);

        $this->assertNotNull($dean->fresh());
    }

    public function test_an_unheld_designation_archives(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);

        $this->actingAs($f['vpaa'])
            ->deleteJson("/api/designations/{$dean->id}")
            ->assertOk();

        $this->assertSoftDeleted('designations', ['id' => $dean->id]);
    }

    public function test_index_reports_how_many_instructors_hold_each_designation(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);
        $f['faculty']->update(['designation_id' => $dean->id, 'deload_units' => 6]);

        $this->actingAs($f['secretary'])
            ->getJson('/api/designations')
            ->assertOk()
            ->assertJsonPath('0.faculties_count', 1);
    }

    public function test_index_can_exclude_inactive_designations(): void
    {
        $f = $this->fixture();
        Designation::create(['name' => 'Dean', 'deload_units' => 6]);
        Designation::create(['name' => 'Retired Post', 'deload_units' => 2, 'status' => 'inactive']);

        $this->actingAs($f['secretary'])
            ->getJson('/api/designations?active_only=1')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_designations_are_ordered_by_sort_order_then_name(): void
    {
        $f = $this->fixture();
        Designation::create(['name' => 'Zulu Post', 'deload_units' => 1, 'sort_order' => 0]);
        Designation::create(['name' => 'Alpha Post', 'deload_units' => 1, 'sort_order' => 0]);
        Designation::create(['name' => 'Top Post', 'deload_units' => 1, 'sort_order' => -0]);

        $names = $this->actingAs($f['secretary'])
            ->getJson('/api/designations')
            ->assertOk()
            ->json('*.name');

        $this->assertSame(['Alpha Post', 'Top Post', 'Zulu Post'], $names);
    }

    public function test_a_role_without_the_capability_cannot_maintain_the_list(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->postJson('/api/designations', ['name' => 'Dean', 'deload_units' => 6])
            ->assertStatus(403);
    }

    public function test_a_role_without_the_capability_cannot_assign_one(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);

        // The secretary may still edit the load allowances; it is the
        // designation alone that is refused.
        $this->actingAs($f['secretary'])
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['designation_id' => $dean->id])
            ->assertStatus(403);

        $this->assertSame(0, (int) $f['faculty']->fresh()->deload_units);
    }

    /**
     * `faculty.manage_designations` is VPAA-only: Manage Access will not offer
     * it to any other role, so the VPAA is the only caller that can assign one.
     */
    public function test_the_vpaa_may_assign_a_designation(): void
    {
        $f = $this->fixture();
        $dean = Designation::create(['name' => 'Dean', 'deload_units' => 6]);

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/faculties/{$f['faculty']->id}", ['designation_id' => $dean->id])
            ->assertOk()
            ->assertJsonPath('deload_units', 6)
            ->assertJsonPath('required_units', 15);
    }

    /**
     * The capability is not delegable, so Manage Access refuses to attach it
     * to any other role -- the config gate and the route gate have to agree,
     * or a grant made in the UI would quietly outlive the policy.
     */
    public function test_the_capability_cannot_be_granted_to_another_role(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['vpaa'])
            ->patchJson("/api/user/{$f['secretary']->id}/permissions", [
                'permissions' => ['schedule.view', 'faculty.manage_designations'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.1']);

        $this->assertFalse($f['secretary']->fresh()->hasCapability('faculty.manage_designations'));
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create([
            'department_name' => 'Designation Dept',
            'department_code' => 'DSG',
        ]);

        Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $secretary = User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]);
        $secretary->givePermissionTo('schedule.view');
        $secretary->givePermissionTo('schedule.assign_instructor');

        return [
            'department' => $department,
            'faculty' => Faculty::create([
                'first_name' => 'Basic',
                'last_name' => 'Instructor',
                'employment_type' => 'full-time',
                'max_units' => 21,
                'deload_units' => 0,
                'overload_units' => 0,
                'probono_units' => 0,
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'vpaa' => User::factory()->create([
                'role' => 'vpaa',
                'department_id' => $department->id,
            ]),
            'secretary' => $secretary->fresh(),
        ];
    }
}
