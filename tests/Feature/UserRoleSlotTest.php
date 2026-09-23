<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Each role slot (Dean and Secretary per department, Program Head per
 * program) holds at most one active account. Deactivating the holder frees
 * the slot for a replacement.
 */
class UserRoleSlotTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private Program $bsit;

    private Program $bscs;

    private User $vpaa;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->department = Departments::create([
            'department_code' => 'CCS',
            'department_name' => 'College of Computer Studies',
        ]);
        $this->bsit = Program::create(['department_id' => $this->department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $this->bscs = Program::create(['department_id' => $this->department->id, 'code' => 'BSCS', 'name' => 'Computer Science']);

        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true]);
        $this->vpaa->syncRoles(['vpaa']);
    }

    public function test_a_second_active_dean_in_the_same_department_is_refused(): void
    {
        $this->createUser('first', ['role' => 'dean'])->assertCreated();

        $this->createUser('second', ['role' => 'dean'])
            ->assertJsonValidationErrors(['role' => 'CCS already has an active Dean']);
    }

    public function test_a_replacement_can_be_added_once_the_holder_is_deactivated(): void
    {
        $first = $this->createUser('first')->assertCreated();
        $this->updateUser($first->json('data.id'), ['is_active' => false])->assertOk();

        $this->createUser('second')->assertCreated();
    }

    public function test_an_inactive_account_can_be_created_while_the_slot_is_taken(): void
    {
        $this->createUser('first')->assertCreated();

        $this->createUser('second', ['is_active' => false])->assertCreated();
    }

    public function test_reactivating_while_the_slot_is_taken_is_refused(): void
    {
        $first = $this->createUser('first')->assertCreated();
        $this->updateUser($first->json('data.id'), ['is_active' => false])->assertOk();
        $this->createUser('second')->assertCreated();

        $this->updateUser($first->json('data.id'), ['is_active' => true])
            ->assertJsonValidationErrors('role');
    }

    public function test_the_holder_can_edit_their_own_account(): void
    {
        $first = $this->createUser('first')->assertCreated();

        $this->updateUser($first->json('data.id'), ['first_name' => 'Renamed'])->assertOk();
    }

    public function test_program_heads_are_limited_per_program_not_per_department(): void
    {
        $this->createUser('it', ['role' => 'program_head', 'program_id' => $this->bsit->id])->assertCreated();
        $this->createUser('cs', ['role' => 'program_head', 'program_id' => $this->bscs->id])->assertCreated();

        $this->createUser('it2', ['role' => 'program_head', 'program_id' => $this->bsit->id])
            ->assertJsonValidationErrors(['role' => 'BSIT already has an active Program Head']);
    }

    public function test_a_replacement_after_archiving_gets_a_numbered_username(): void
    {
        $first = $this->createUser('first', ['username' => 'ccssecretary'])->assertCreated();
        $this->updateUser($first->json('data.id'), ['is_active' => false])->assertOk();
        $this->actingAs($this->vpaa, 'sanctum')->deleteJson('/api/user/'.$first->json('data.id'))->assertOk();

        $this->createUser('second', ['username' => 'ccssecretary'])
            ->assertCreated()
            ->assertJsonPath('data.username', 'ccssecretary2');
        $this->createUser('third', ['username' => 'CCSSecretary', 'is_active' => false])
            ->assertCreated()
            ->assertJsonPath('data.username', 'ccssecretary3');
    }

    public function test_an_archived_accounts_email_points_to_restore(): void
    {
        $first = $this->createUser('first')->assertCreated();
        $this->actingAs($this->vpaa, 'sanctum')->deleteJson('/api/user/'.$first->json('data.id'))->assertOk();

        $this->createUser('first', ['username' => 'someone.else'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This email belongs to an existing or archived account. If it was archived, restore it from Archives instead.');
    }

    private function createUser(string $handle, array $overrides = [])
    {
        return $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/user', $overrides + [
            'first_name' => ucfirst($handle),
            'last_name' => 'Account',
            'username' => "{$handle}.account",
            'email' => "{$handle}.account@school.edu.ph",
            'password' => 'StrongPass123',
            'role' => 'secretary',
            'department_id' => $this->department->id,
            'faculty_mode' => 'none',
        ]);
    }

    private function updateUser(int $id, array $overrides)
    {
        $user = User::findOrFail($id);

        return $this->actingAs($this->vpaa, 'sanctum')->putJson("/api/user/{$id}", $overrides + [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role,
            'department_id' => $user->department_id,
            'program_id' => $user->program_id,
            'is_active' => $user->is_active,
        ]);
    }
}
