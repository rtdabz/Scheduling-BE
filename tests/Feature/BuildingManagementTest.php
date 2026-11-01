<?php

namespace Tests\Feature;

use App\Models\Rooms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A building is the rooms that name it; renaming or archiving it acts on all of them. */
class BuildingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_building_name_can_be_used_for_a_new_room(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/rooms', ['room_code' => 'CIT-101', 'building' => 'CIT Building', 'room_type' => 'lecture'])
            ->assertCreated();

        $this->assertDatabaseHas('rooms', ['room_code' => 'CIT-101', 'building' => 'CIT Building']);
    }

    public function test_renaming_moves_every_room_of_the_building_and_no_other(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $a = Rooms::create(['room_code' => 'B1-101', 'building' => 'Building 1', 'room_type' => 'lecture']);
        $b = Rooms::create(['room_code' => 'B1-102', 'building' => 'Building 1', 'room_type' => 'laboratory']);
        $other = Rooms::create(['room_code' => 'B2-101', 'building' => 'Building 2', 'room_type' => 'lecture']);

        $this->actingAs($vpaa, 'sanctum')
            ->putJson('/api/buildings', ['building' => 'Building 1', 'name' => '  Main   Hall '])
            ->assertOk()
            ->assertJsonPath('building', 'Main Hall')
            ->assertJsonCount(2, 'rooms');

        $this->assertDatabaseHas('rooms', ['id' => $a->id, 'building' => 'Main Hall']);
        $this->assertDatabaseHas('rooms', ['id' => $b->id, 'building' => 'Main Hall']);
        $this->assertDatabaseHas('rooms', ['id' => $other->id, 'building' => 'Building 2']);
    }

    public function test_renaming_onto_another_building_is_refused_but_recasing_its_own_name_is_not(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $room = Rooms::create(['room_code' => 'B1-101', 'building' => 'Building 1', 'room_type' => 'lecture']);
        Rooms::create(['room_code' => 'B2-101', 'building' => 'Building 2', 'room_type' => 'lecture']);

        $this->actingAs($vpaa, 'sanctum')
            ->putJson('/api/buildings', ['building' => 'Building 1', 'name' => 'building 2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'building' => 'Building 1']);

        $this->actingAs($vpaa, 'sanctum')
            ->putJson('/api/buildings', ['building' => 'Building 1', 'name' => 'BUILDING 1'])
            ->assertOk();
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'building' => 'BUILDING 1']);
    }

    public function test_archiving_soft_deletes_every_room_of_the_building(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $a = Rooms::create(['room_code' => 'B1-101', 'building' => 'Building 1', 'room_type' => 'lecture']);
        $b = Rooms::create(['room_code' => 'B1-102', 'building' => 'Building 1', 'room_type' => 'lecture']);
        $other = Rooms::create(['room_code' => 'B2-101', 'building' => 'Building 2', 'room_type' => 'lecture']);

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/buildings/archive', ['building' => 'Building 1'])
            ->assertOk();

        $this->assertSoftDeleted('rooms', ['id' => $a->id]);
        $this->assertSoftDeleted('rooms', ['id' => $b->id]);
        $this->assertNotSoftDeleted('rooms', ['id' => $other->id]);
    }

    public function test_an_unknown_building_is_not_found(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);

        $this->actingAs($vpaa, 'sanctum')
            ->putJson('/api/buildings', ['building' => 'Nowhere', 'name' => 'Somewhere'])
            ->assertNotFound();
        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/buildings/archive', ['building' => 'Nowhere'])
            ->assertNotFound();
    }

    public function test_only_the_vpaa_manages_buildings(): void
    {
        $dean = User::factory()->create(['role' => 'dean']);
        $room = Rooms::create(['room_code' => 'B1-101', 'building' => 'Building 1', 'room_type' => 'lecture']);

        $this->actingAs($dean, 'sanctum')
            ->putJson('/api/buildings', ['building' => 'Building 1', 'name' => 'Main Hall'])
            ->assertForbidden();
        $this->actingAs($dean, 'sanctum')
            ->postJson('/api/buildings/archive', ['building' => 'Building 1'])
            ->assertForbidden();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'building' => 'Building 1', 'deleted_at' => null]);
    }

    public function test_a_new_room_joins_the_existing_spelling_of_its_building(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        Rooms::create(['room_code' => 'B1-101', 'building' => 'Main Hall', 'room_type' => 'lecture']);

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/rooms', ['room_code' => 'B1-102', 'building' => '  main   HALL ', 'room_type' => 'lecture'])
            ->assertCreated()
            ->assertJsonPath('room.building', 'Main Hall');
    }

    public function test_renaming_and_archiving_reach_rooms_whose_names_differ_only_in_case_or_spacing(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $a = Rooms::create(['room_code' => 'B1-101', 'building' => 'Main Hall', 'room_type' => 'lecture']);
        $b = Rooms::create(['room_code' => 'B1-102', 'building' => ' main  hall', 'room_type' => 'lecture']);
        $other = Rooms::create(['room_code' => 'B2-101', 'building' => 'Annex', 'room_type' => 'lecture']);

        $this->actingAs($vpaa, 'sanctum')
            ->putJson('/api/buildings', ['building' => 'MAIN HALL', 'name' => 'North Hall'])
            ->assertOk()
            ->assertJsonCount(2, 'rooms');
        $this->assertDatabaseHas('rooms', ['id' => $b->id, 'building' => 'North Hall']);

        $this->actingAs($vpaa, 'sanctum')
            ->putJson('/api/buildings', ['building' => 'Annex', 'name' => 'north   hall'])
            ->assertUnprocessable();

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/buildings/archive', ['building' => 'north hall'])
            ->assertOk();
        $this->assertSoftDeleted('rooms', ['id' => $a->id]);
        $this->assertSoftDeleted('rooms', ['id' => $b->id]);
        $this->assertNotSoftDeleted('rooms', ['id' => $other->id]);
    }

    public function test_the_secretary_cannot_manage_rooms_or_buildings(): void
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $room = Rooms::create(['room_code' => 'B1-101', 'building' => 'Building 1', 'room_type' => 'lecture']);

        $this->actingAs($secretary, 'sanctum')
            ->postJson('/api/rooms', ['room_code' => 'B1-102', 'building' => 'Building 1', 'room_type' => 'lecture'])
            ->assertForbidden();
        $this->actingAs($secretary, 'sanctum')
            ->postJson('/api/buildings/archive', ['building' => 'Building 1'])
            ->assertForbidden();
        $this->actingAs($secretary, 'sanctum')
            ->deleteJson("/api/rooms/{$room->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('rooms', ['id' => $room->id]);
    }
}
