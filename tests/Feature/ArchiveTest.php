<?php

namespace Tests\Feature;

use App\Models\Departments;
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
            'name' => 'Department Secretary',
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
            'administrative_role' => 'secretary',
            'deleted_at' => null,
        ]);
    }
}
