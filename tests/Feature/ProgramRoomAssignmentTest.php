<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A department secretary dividing the department's rooms between its programs:
 * CAS with programs X and Y and three lecture rooms, one for each program and
 * one shared. Only the setup is covered here; the generator does not read it yet.
 */
class ProgramRoomAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_sees_only_the_departments_own_lecture_and_laboratory_rooms(): void
    {
        $f = $this->fixture();
        $this->room('FIELD-1', 'field', $f['cas']->id);
        $this->room('CIT-101', 'lecture', $f['cit']->id);
        $this->room('GEN-1', 'lecture', null);

        $response = $this->actingAs($f['secretary'])
            ->getJson('/api/program-rooms')
            ->assertOk()
            ->assertJsonPath('data.room_sharing_policy', 'open')
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.programs.0.code', 'X')
            ->assertJsonPath('data.programs.1.code', 'Y');

        $this->assertSame(['CAS-101', 'CAS-102', 'CAS-LAB'], array_column($response->json('data.rooms'), 'room_code'));
    }

    public function test_secretary_gives_rooms_home_programs_and_chooses_the_policy(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->putJson('/api/program-rooms', [
                'room_sharing_policy' => 'home_first',
                'assignments' => [
                    ['room_id' => $f['room1']->id, 'program_id' => $f['x']->id],
                    ['room_id' => $f['room2']->id, 'program_id' => $f['y']->id],
                    ['room_id' => $f['room3']->id, 'program_id' => null],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.room_sharing_policy', 'home_first')
            ->assertJsonPath('data.rooms.0.home_program_id', $f['x']->id)
            ->assertJsonPath('data.rooms.1.home_program_id', $f['y']->id)
            ->assertJsonPath('data.rooms.2.home_program_id', null);

        $this->assertSame('home_first', $f['cas']->fresh()->room_sharing_policy);
        $this->assertSame($f['x']->id, $f['room1']->fresh()->home_program_id);
        $this->assertNull($f['room3']->fresh()->home_program_id);
    }

    public function test_another_departments_room_or_program_is_refused(): void
    {
        $f = $this->fixture();
        $citRoom = $this->room('CIT-101', 'lecture', $f['cit']->id);
        $citProgram = Program::create(['department_id' => $f['cit']->id, 'code' => 'BSIT', 'name' => 'Information Technology']);

        $this->actingAs($f['secretary'])
            ->putJson('/api/program-rooms', ['assignments' => [
                ['room_id' => $citRoom->id, 'program_id' => $f['x']->id],
                ['room_id' => $f['room1']->id, 'program_id' => $citProgram->id],
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignments.0.room_id', 'assignments.1.program_id']);

        $this->assertNull($citRoom->fresh()->home_program_id);
        $this->assertNull($f['room1']->fresh()->home_program_id);
    }

    public function test_an_unknown_policy_is_refused(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->putJson('/api/program-rooms', ['room_sharing_policy' => 'first_come'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_sharing_policy');
    }

    public function test_program_heads_and_deans_can_read_but_not_change_the_arrangement(): void
    {
        $f = $this->fixture();
        $programHead = User::factory()->create(['role' => 'program_head', 'department_id' => $f['cas']->id, 'program_id' => $f['x']->id]);
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $f['cas']->id]);

        $this->actingAs($programHead)
            ->getJson('/api/program-rooms')
            ->assertOk()
            ->assertJsonPath('data.can_manage', false);

        foreach ([$programHead, $dean] as $user) {
            $this->actingAs($user)
                ->putJson('/api/program-rooms', ['room_sharing_policy' => 'strict'])
                ->assertForbidden();
        }

        $this->assertSame('open', $f['cas']->fresh()->room_sharing_policy);
    }

    public function test_moving_a_room_to_another_department_or_making_it_a_field_room_drops_its_home_program(): void
    {
        $f = $this->fixture();
        $f['room1']->forceFill(['home_program_id' => $f['x']->id])->save();
        $f['room2']->forceFill(['home_program_id' => $f['y']->id])->save();

        $f['room1']->update(['department_id' => $f['cit']->id]);
        $f['room2']->update(['room_type' => 'field']);

        $this->assertNull($f['room1']->fresh()->home_program_id);
        $this->assertNull($f['room2']->fresh()->home_program_id);
    }

    public function test_an_archived_program_leaves_its_room_shared(): void
    {
        $f = $this->fixture();
        $f['room1']->forceFill(['home_program_id' => $f['x']->id])->save();
        $f['x']->delete();

        $this->actingAs($f['secretary'])
            ->getJson('/api/program-rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data.programs')
            ->assertJsonPath('data.rooms.0.home_program_id', null);
    }

    private function room(string $code, string $type, ?int $departmentId): Rooms
    {
        return Rooms::create([
            'room_code' => $code,
            'building' => 'Main',
            'room_type' => $type,
            'status' => 'available',
            'department_id' => $departmentId,
        ]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $cas = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        $cit = Departments::create(['department_name' => 'College of Information Technology', 'department_code' => 'CIT']);

        return [
            'cas' => $cas,
            'cit' => $cit,
            'x' => Program::create(['department_id' => $cas->id, 'code' => 'X', 'name' => 'Program X']),
            'y' => Program::create(['department_id' => $cas->id, 'code' => 'Y', 'name' => 'Program Y']),
            'room1' => $this->room('CAS-101', 'lecture', $cas->id),
            'room2' => $this->room('CAS-102', 'lecture', $cas->id),
            'room3' => $this->room('CAS-LAB', 'laboratory', $cas->id),
            'secretary' => User::factory()->create(['role' => 'secretary', 'department_id' => $cas->id]),
        ];
    }
}
