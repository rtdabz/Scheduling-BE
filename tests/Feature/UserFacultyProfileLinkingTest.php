<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Creating an account decides the instructor profile explicitly: a fresh one,
 * a link to an instructor already on the roster, or none for non-teaching
 * staff. Linking is what keeps one person from appearing twice to the
 * generator.
 */
class UserFacultyProfileLinkingTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

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
        Program::create([
            'department_id' => $this->department->id,
            'code' => 'BSIT',
            'name' => 'Bachelor of Science in Information Technology',
        ]);

        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true]);
        $this->vpaa->syncRoles(['vpaa']);
    }

    public function test_omitting_the_mode_still_creates_a_profile(): void
    {
        $user = $this->createUser()->assertCreated();

        $this->assertDatabaseHas('faculties', [
            'user_id' => $user->json('data.id'),
            'administrative_role' => 'secretary',
            'deload_units' => 0,
        ]);
    }

    public function test_create_mode_applies_the_designation_deload(): void
    {
        $designation = $this->designation(6);

        $user = $this->createUser(['faculty_mode' => 'create', 'designation_id' => $designation->id])
            ->assertCreated();

        $this->assertDatabaseHas('faculties', [
            'user_id' => $user->json('data.id'),
            'designation_id' => $designation->id,
            'deload_units' => 6,
        ]);
    }

    public function test_link_mode_attaches_the_existing_instructor_without_duplicating(): void
    {
        $roster = $this->rosterInstructor(['max_units' => 18, 'employment_type' => 'part-time']);

        $user = $this->createUser(['faculty_mode' => 'link', 'faculty_id' => $roster->id])
            ->assertCreated()
            ->assertJsonPath('data.faculty_profile.id', $roster->id);

        $this->assertSame(1, Faculty::count());
        $roster->refresh();
        $this->assertSame($user->json('data.id'), $roster->user_id);
        $this->assertSame('secretary', $roster->administrative_role);
        // The roster record's load is the instructor's, not a default.
        $this->assertSame(18, (int) $roster->max_units);
        $this->assertSame('part-time', $roster->employment_type);
    }

    public function test_link_mode_refuses_an_instructor_already_linked(): void
    {
        $owner = User::factory()->create(['role' => 'dean', 'department_id' => $this->department->id]);
        $roster = $this->rosterInstructor(['user_id' => $owner->id]);

        $this->createUser(['faculty_mode' => 'link', 'faculty_id' => $roster->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('faculty_id');

        $this->assertSame($owner->id, $roster->refresh()->user_id);
        $this->assertDatabaseMissing('users', ['username' => 'new.account']);
    }

    public function test_link_mode_refuses_an_instructor_from_another_department(): void
    {
        $other = Departments::create(['department_code' => 'CAS', 'department_name' => 'Arts and Sciences']);
        $roster = $this->rosterInstructor(['department_id' => $other->id]);

        $this->createUser(['faculty_mode' => 'link', 'faculty_id' => $roster->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('faculty_id');

        $this->assertNull($roster->refresh()->user_id);
    }

    public function test_link_mode_requires_an_instructor(): void
    {
        $this->createUser(['faculty_mode' => 'link'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('faculty_id');
    }

    public function test_none_mode_creates_no_profile_and_later_edits_do_not_add_one(): void
    {
        $response = $this->createUser(['faculty_mode' => 'none'])->assertCreated();
        $userId = $response->json('data.id');

        $this->assertDatabaseMissing('faculties', ['user_id' => $userId]);

        $this->actingAs($this->vpaa, 'sanctum')->putJson("/api/user/{$userId}", [
            'first_name' => 'Renamed',
            'last_name' => 'Account',
            'email' => 'new.account@school.edu.ph',
            'role' => 'secretary',
            'department_id' => $this->department->id,
            'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseMissing('faculties', ['user_id' => $userId]);
    }

    public function test_linkable_faculty_lists_only_unlinked_instructors_in_the_department(): void
    {
        $owner = User::factory()->create(['role' => 'dean', 'department_id' => $this->department->id]);
        $free = $this->rosterInstructor();
        $this->rosterInstructor(['user_id' => $owner->id, 'first_name' => 'Taken']);
        $other = Departments::create(['department_code' => 'CAS', 'department_name' => 'Arts and Sciences']);
        $this->rosterInstructor(['department_id' => $other->id, 'first_name' => 'Elsewhere']);

        $this->actingAs($this->vpaa, 'sanctum')
            ->getJson("/api/user/linkable-faculty?department_id={$this->department->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $free->id);
    }

    private function createUser(array $overrides = [])
    {
        return $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/user', $overrides + [
            'first_name' => 'New',
            'last_name' => 'Account',
            'username' => 'new.account',
            'email' => 'new.account@school.edu.ph',
            'password' => 'StrongPass123',
            'role' => 'secretary',
            'department_id' => $this->department->id,
        ]);
    }

    private function rosterInstructor(array $overrides = []): Faculty
    {
        return Faculty::create($overrides + [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'overload_units' => 0,
            'deload_units' => 0,
            'probono_units' => 0,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
    }

    private function designation(int $deload): Designation
    {
        return Designation::create([
            'name' => 'Department Secretary',
            'deload_units' => $deload,
            'status' => 'active',
        ]);
    }
}
