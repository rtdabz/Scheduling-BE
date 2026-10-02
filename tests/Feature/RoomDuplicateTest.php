<?php

namespace Tests\Feature;

use App\Models\Rooms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A room code names one room, however it is typed. */
class RoomDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_room_code_in_another_case_or_spacing_is_refused(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        Rooms::create(['room_code' => 'RM 101', 'room_type' => 'lecture']);

        foreach (['rm 101', '  RM   101 ', 'Rm 101'] as $code) {
            $this->actingAs($vpaa, 'sanctum')
                ->postJson('/api/rooms', ['room_code' => $code, 'room_type' => 'lecture'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('room_code');
        }

        $this->assertSame(1, Rooms::query()->count());
    }

    public function test_spacing_is_tidied_when_a_room_is_saved(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/rooms', ['room_code' => '  LAB   2 ', 'room_type' => 'lecture'])
            ->assertSuccessful();

        $this->assertDatabaseHas('rooms', ['room_code' => 'LAB 2']);
    }

    public function test_renaming_onto_another_room_is_refused_but_keeping_its_own_code_is_not(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        Rooms::create(['room_code' => 'RM 101', 'room_type' => 'lecture']);
        $other = Rooms::create(['room_code' => 'RM 102', 'room_type' => 'lecture']);

        $this->actingAs($vpaa, 'sanctum')
            ->putJson("/api/rooms/{$other->id}", ['room_code' => 'rm 101'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_code');

        $this->actingAs($vpaa, 'sanctum')
            ->putJson("/api/rooms/{$other->id}", ['room_code' => 'rm 102'])
            ->assertOk();
    }
}
