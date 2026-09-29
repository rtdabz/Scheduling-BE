<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Designation;
use App\Models\Semester;
use App\Models\TimeslotOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smaller CRUD corrections: what an edited department returns, archived
 * designations, the wording of unique-name refusals, and input limits.
 */
class CrudAuditFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_department_still_returns_its_secretary_and_program_heads(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create(['department_name' => 'Computing', 'department_code' => 'CCS']);
        User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $roles = collect($this->actingAs($vpaa, 'sanctum')
            ->patchJson("/api/departments/{$department->id}", ['department_name' => 'Computing Studies'])
            ->assertOk()
            ->json('users'))->pluck('role')->sort()->values()->all();

        $this->assertSame(['dean', 'secretary'], $roles);
    }

    public function test_a_designation_can_be_archived_listed_and_restored(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $designation = Designation::create(['name' => 'Coordinator', 'deload_units' => 3, 'status' => 'active']);

        $this->actingAs($vpaa, 'sanctum')->deleteJson("/api/designations/{$designation->id}")->assertOk();

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/archives')
            ->assertOk()
            ->assertJsonFragment(['type' => 'designations', 'label' => 'Coordinator']);

        $this->actingAs($vpaa, 'sanctum')
            ->postJson("/api/archives/designations/{$designation->id}/restore")
            ->assertOk();
        $this->assertNotNull(Designation::find($designation->id));
    }

    public function test_a_designation_cannot_return_under_an_archived_parent_or_onto_a_taken_name(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $parent = Designation::create(['name' => 'Director', 'deload_units' => 0, 'status' => 'active']);
        $child = Designation::create(['name' => 'Networking', 'deload_units' => 3, 'status' => 'active', 'parent_id' => $parent->id]);
        $child->delete();
        $parent->delete();

        $this->actingAs($vpaa, 'sanctum')
            ->postJson("/api/archives/designations/{$child->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Restore its parent designation from Archives first.');

        $this->actingAs($vpaa, 'sanctum')->postJson("/api/archives/designations/{$parent->id}/restore")->assertOk();
        Designation::create(['name' => 'Networking', 'deload_units' => 3, 'status' => 'active', 'parent_id' => $parent->id]);

        $this->actingAs($vpaa, 'sanctum')
            ->postJson("/api/archives/designations/{$child->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A designation with this name already exists here. Rename it first.');
    }

    public function test_a_restored_timeslot_override_is_live_again(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $override = TimeslotOverride::query()->create(['duration_minutes' => 90, 'start_time' => '08:00:00', 'is_active' => true]);
        $override->delete();

        $this->actingAs($vpaa, 'sanctum')
            ->postJson("/api/archives/timeslot-overrides/{$override->id}/restore")
            ->assertOk();

        $this->assertNotNull(TimeslotOverride::find($override->id));
    }

    public function test_a_taken_name_that_is_archived_says_so(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create(['department_name' => 'Old College', 'department_code' => 'OLD']);
        $department->delete();

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/departments', ['department_name' => 'Old College', 'department_code' => 'NEW'])
            ->assertStatus(422)
            ->assertJsonPath('errors.department_name.0', fn (string $message) => str_contains($message, 'archived'));
    }

    public function test_a_new_semester_must_use_two_consecutive_years(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);

        foreach (['soon', '2026-2028', '2026'] as $year) {
            $this->actingAs($vpaa, 'sanctum')
                ->postJson('/api/semesters', ['semester' => '1st', 'academic_year' => $year])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['academic_year']);
        }

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/semesters', ['semester' => '1st', 'academic_year' => '2030-2031'])
            ->assertCreated();
        $this->assertSame(1, Semester::where('academic_year', '2030-2031')->count());
    }

    public function test_a_profile_picture_must_be_a_bounded_image_data_url(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create(['department_name' => 'Computing', 'department_code' => 'CCS']);
        $payload = fn (?string $picture) => [
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'username' => 'ana.cruz',
            'email' => 'ana@example.com', 'role' => 'dean', 'department_id' => $department->id,
            'profile_picture' => $picture,
        ];

        foreach (['javascript:alert(1)', 'data:image/jpeg;base64,'.str_repeat('A', 1_500_000)] as $bad) {
            $this->actingAs($vpaa, 'sanctum')
                ->postJson('/api/user', $payload($bad))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['profile_picture']);
        }

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/user', $payload('data:image/jpeg;base64,AAAA'))
            ->assertCreated();
    }
}
