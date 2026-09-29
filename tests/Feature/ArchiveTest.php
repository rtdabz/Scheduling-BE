<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_delete_archives_and_vpaa_can_restore_the_record(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create([
            'department_name' => 'College of Computing Studies',
            'department_code' => 'CCS',
        ]);
        $room = Rooms::create([
            'room_code' => 'LAB-101',
            'room_type' => 'laboratory',
            'department_id' => $department->id,
        ]);

        $this->actingAs($vpaa, 'sanctum')
            ->deleteJson("/api/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Room archived successfully.');

        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
        $this->assertNull(Rooms::find($room->id));

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/archives')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'rooms')
            ->assertJsonPath('data.0.label', 'LAB-101');

        $this->actingAs($vpaa, 'sanctum')
            ->postJson("/api/archives/rooms/{$room->id}/restore")
            ->assertOk();

        $this->assertNotNull(Rooms::find($room->id));
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'deleted_at' => null]);
    }

    public function test_restore_is_audited_and_archive_lists_a_count_for_every_type(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $room = Rooms::create(['room_code' => 'AUD-101', 'room_type' => 'lecture']);
        $room->delete();

        $this->actingAs($vpaa, 'sanctum')
            ->getJson('/api/archives')
            ->assertOk()
            ->assertJsonPath('counts.rooms', 1)
            ->assertJsonPath('counts.schedules', 0);

        $this->actingAs($vpaa, 'sanctum')
            ->postJson("/api/archives/rooms/{$room->id}/restore")
            ->assertOk();

        $this->assertDatabaseHas('scheduling_audit_logs', ['action' => 'record_restored', 'user_id' => $vpaa->id]);
    }

    public function test_an_archived_room_code_can_be_reused_by_a_new_room(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        Rooms::create(['room_code' => 'REUSE-1', 'room_type' => 'lecture'])->delete();

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/rooms', ['room_code' => 'REUSE-1', 'room_type' => 'lecture'])
            ->assertSuccessful();
    }

    public function test_a_department_in_use_cannot_be_archived(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create(['department_name' => 'In Use Dept', 'department_code' => 'IUD']);
        Rooms::create(['room_code' => 'IUD-101', 'room_type' => 'lecture', 'department_id' => $department->id]);

        $this->actingAs($vpaa, 'sanctum')
            ->deleteJson("/api/departments/{$department->id}")
            ->assertStatus(422);

        $this->assertNotNull(Departments::find($department->id));
    }

    public function test_a_department_logo_must_be_a_bounded_image_data_url(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create(['department_name' => 'Logo Dept', 'department_code' => 'LGD']);

        foreach (['javascript:alert(1)', 'data:image/jpeg;base64,'.str_repeat('A', 200000)] as $logo) {
            $this->actingAs($vpaa, 'sanctum')
                ->putJson("/api/departments/{$department->id}", ['logo' => $logo])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['logo']);
        }

        $this->actingAs($vpaa, 'sanctum')
            ->putJson("/api/departments/{$department->id}", ['logo' => 'data:image/jpeg;base64,AAAA'])
            ->assertOk();
    }

    public function test_non_vpaa_cannot_access_the_archive(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary, 'sanctum')
            ->getJson('/api/archives')
            ->assertForbidden();
    }

    public function test_archiving_a_user_preserves_the_linked_faculty_profile(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create([
            'department_name' => 'College of Education',
            'department_code' => 'CED',
        ]);

        $user = $this->actingAs($vpaa, 'sanctum')->postJson('/api/user', [
            'first_name' => 'Department',
            'last_name' => 'Secretary',
            'username' => 'department.secretary',
            'email' => 'secretary@example.com',
            'password' => 'StrongPass123',
            'role' => 'secretary',
            'department_id' => $department->id,
        ])->assertCreated()->json('data');

        $this->actingAs($vpaa, 'sanctum')
            ->deleteJson("/api/user/{$user['id']}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user['id']]);
        $this->assertDatabaseHas('faculties', [
            'user_id' => $user['id'],
            'administrative_role' => null,
            'deleted_at' => null,
        ]);

        // With the account archived, the profile itself can now be archived.
        $faculty = Faculty::where('user_id', $user['id'])->firstOrFail();
        $this->actingAs($vpaa, 'sanctum')
            ->deleteJson("/api/faculties/{$faculty->id}")
            ->assertOk();
    }

    public function test_restoring_an_archived_user_restores_the_profile_role(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create([
            'department_name' => 'College of Education',
            'department_code' => 'CED',
        ]);

        $user = $this->actingAs($vpaa, 'sanctum')->postJson('/api/user', [
            'first_name' => 'Department',
            'last_name' => 'Secretary',
            'username' => 'department.secretary',
            'email' => 'secretary@example.com',
            'password' => 'StrongPass123',
            'role' => 'secretary',
            'department_id' => $department->id,
        ])->assertCreated()->json('data');

        $this->actingAs($vpaa, 'sanctum')->deleteJson("/api/user/{$user['id']}")->assertOk();
        $this->actingAs($vpaa, 'sanctum')->postJson("/api/archives/users/{$user['id']}/restore")->assertOk();

        $this->assertDatabaseHas('faculties', [
            'user_id' => $user['id'],
            'administrative_role' => 'secretary',
        ]);
    }
}
