<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use App\Support\AccountVersion;
use App\Support\PersonName;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Optional title ("Dr.") and credentials ("DM", "PhD, EdD") on accounts, instructor
 * profiles and My Profile. They are display-only and travel with account/profile sync.
 */
class NameTitleCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private User $vpaa;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->department = Departments::create(['department_code' => 'CCS', 'department_name' => 'College of Computer Studies']);
        Program::create(['department_id' => $this->department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology']);

        $this->vpaa = User::factory()->create(['role' => 'vpaa', 'is_active' => true]);
        $this->vpaa->syncRoles(['vpaa']);
    }

    // --- Accounts -------------------------------------------------------------

    public function test_account_saves_title_and_credentials_and_carries_them_to_the_new_profile(): void
    {
        $response = $this->createUser([
            'honorific_prefix' => 'Dr.',
            'first_name' => 'Juan',
            'middle_initial' => 'p',
            'last_name' => 'Dela Cruz',
            'suffix' => 'Jr.',
            'credentials' => '  DM ',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Dr. Juan P. Dela Cruz Jr., DM')
            ->assertJsonPath('data.honorific_prefix', 'Dr.')
            ->assertJsonPath('data.credentials', 'DM')
            ->assertJsonPath('data.suffix', 'Jr.');

        $this->assertDatabaseHas('faculties', [
            'user_id' => $response->json('data.id'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'suffix' => 'Jr.',
            'honorific_prefix' => 'Dr.',
            'credentials' => 'DM',
        ]);
    }

    public function test_title_must_come_from_the_list_and_credentials_are_bounded(): void
    {
        $this->createUser(['honorific_prefix' => 'Sir'])->assertJsonValidationErrors('honorific_prefix');
        $this->createUser(['credentials' => str_repeat('A', PersonName::CREDENTIALS_MAX + 1)])->assertJsonValidationErrors('credentials');
        $this->createUser(['credentials' => 'PhD <script>'])->assertJsonValidationErrors('credentials');
    }

    public function test_account_update_keeps_omitted_fields_and_clears_explicit_nulls_on_the_profile_too(): void
    {
        $id = $this->createUser(['first_name' => 'Ana', 'last_name' => 'Reyes', 'honorific_prefix' => 'Dr.', 'credentials' => 'PhD, EdD'])
            ->assertCreated()->json('data.id');
        $user = User::findOrFail($id);

        // Omitted: unchanged.
        $this->updateUser($user, [])->assertOk()
            ->assertJsonPath('data.name', 'Dr. Ana Reyes, PhD, EdD')
            ->assertJsonPath('data.credentials', 'PhD, EdD');

        // Explicit null: cleared on the account and the linked profile.
        $this->updateUser($user, ['honorific_prefix' => null, 'credentials' => null])->assertOk()
            ->assertJsonPath('data.name', 'Ana Reyes')
            ->assertJsonPath('data.honorific_prefix', null)
            ->assertJsonPath('data.credentials', null);

        $faculty = Faculty::where('user_id', $id)->firstOrFail();
        $this->assertNull($faculty->honorific_prefix);
        $this->assertNull($faculty->credentials);
    }

    public function test_existing_accounts_without_the_new_fields_keep_their_name(): void
    {
        $id = $this->createUser(['first_name' => 'Maria', 'middle_initial' => 'c', 'last_name' => 'Santos'])->assertCreated()->json('data.id');
        $user = User::findOrFail($id);
        $this->assertNull($user->honorific_prefix);
        $this->assertNull($user->credentials);

        $this->updateUser($user, [])->assertOk()->assertJsonPath('data.name', 'Maria C. Santos');
    }

    // --- Linking --------------------------------------------------------------

    public function test_linking_keeps_credentials_already_on_the_roster_instructor(): void
    {
        $roster = $this->rosterInstructor(['honorific_prefix' => 'Dr.', 'credentials' => 'PhD']);

        $id = $this->createUser(['first_name' => 'Maria', 'last_name' => 'Santos', 'faculty_mode' => 'link', 'faculty_id' => $roster->id])
            ->assertCreated()->json('data.id');

        $roster->refresh();
        $this->assertSame('Dr.', $roster->honorific_prefix);
        $this->assertSame('PhD', $roster->credentials);
        $user = User::findOrFail($id);
        $this->assertSame('PhD', $user->credentials);
        $this->assertSame('Dr. Maria Santos, PhD', $user->name);

        // A later account edit that does not touch them does not wipe them.
        $this->updateUser($user, [])->assertOk();
        $this->assertSame('PhD', $roster->refresh()->credentials);
    }

    public function test_values_entered_on_the_account_win_when_linking(): void
    {
        $roster = $this->rosterInstructor(['credentials' => 'MA']);

        $this->createUser(['first_name' => 'Maria', 'last_name' => 'Santos', 'credentials' => 'PhD', 'faculty_mode' => 'link', 'faculty_id' => $roster->id])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Maria Santos, PhD');

        $this->assertSame('PhD', $roster->refresh()->credentials);
    }

    public function test_editing_a_linked_instructor_updates_the_account_so_sync_does_not_undo_it(): void
    {
        $id = $this->createUser(['first_name' => 'Ana', 'last_name' => 'Reyes'])->assertCreated()->json('data.id');
        $faculty = Faculty::where('user_id', $id)->firstOrFail();

        $this->actingAs($this->vpaa, 'sanctum')
            ->patchJson("/api/faculties/{$faculty->id}", ['honorific_prefix' => 'Prof.', 'credentials' => 'MIT'])
            ->assertOk();

        $user = User::findOrFail($id);
        $this->assertSame('Prof. Ana Reyes, MIT', $user->name);

        $this->updateUser($user, [])->assertOk();
        $this->assertSame('MIT', $faculty->refresh()->credentials);
    }

    // --- Instructors ----------------------------------------------------------

    public function test_instructor_saves_edits_and_clears_title_and_credentials(): void
    {
        $id = $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/faculties', $this->facultyPayload(['honorific_prefix' => 'Dr.', 'credentials' => ' PhD,   EdD ']))
            ->assertCreated()
            ->assertJsonPath('honorific_prefix', 'Dr.')
            ->assertJsonPath('credentials', 'PhD, EdD')
            ->json('id');

        // Partial update without the fields leaves them alone.
        $this->actingAs($this->vpaa, 'sanctum')
            ->patchJson("/api/faculties/{$id}", ['first_name' => 'Jose'])
            ->assertOk()
            ->assertJsonPath('credentials', 'PhD, EdD');

        $this->actingAs($this->vpaa, 'sanctum')
            ->patchJson("/api/faculties/{$id}", ['credentials' => 'DM'])
            ->assertOk()
            ->assertJsonPath('honorific_prefix', 'Dr.')
            ->assertJsonPath('credentials', 'DM');

        $this->actingAs($this->vpaa, 'sanctum')
            ->patchJson("/api/faculties/{$id}", ['honorific_prefix' => null, 'credentials' => null])
            ->assertOk()
            ->assertJsonPath('honorific_prefix', null)
            ->assertJsonPath('credentials', null);

        $this->actingAs($this->vpaa, 'sanctum')
            ->patchJson("/api/faculties/{$id}", ['honorific_prefix' => 'Doctor'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.honorific_prefix.0', 'Choose a title from the list.');
    }

    public function test_duplicate_detection_ignores_title_and_credentials(): void
    {
        $this->rosterInstructor(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $this->actingAs($this->vpaa, 'sanctum')
            ->postJson('/api/faculties', $this->facultyPayload(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'honorific_prefix' => 'Dr.', 'credentials' => 'PhD']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('first_name');
    }

    public function test_load_only_editors_still_cannot_change_names(): void
    {
        $faculty = $this->rosterInstructor();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id, 'is_active' => true]);
        $secretary->syncRoles(['secretary']);

        $this->actingAs($secretary, 'sanctum')
            ->patchJson("/api/faculties/{$faculty->id}", ['credentials' => 'PhD'])
            ->assertForbidden();
        $this->assertNull($faculty->refresh()->credentials);
    }

    // --- My Profile -----------------------------------------------------------

    public function test_my_profile_shows_saves_keeps_and_clears_the_fields(): void
    {
        $id = $this->createUser(['first_name' => 'Ana', 'last_name' => 'Reyes'])->assertCreated()->json('data.id');
        $user = User::findOrFail($id);

        $this->actingAs($user, 'sanctum')->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.honorific_prefix', null)
            ->assertJsonPath('user.credentials', null)
            ->assertJsonPath('honorific_prefixes', PersonName::HONORIFIC_PREFIXES);

        $name = ['first_name' => 'Ana', 'last_name' => 'Reyes'];
        $this->actingAs($user->fresh(), 'sanctum')->patchJson('/api/profile', ['expected_version' => AccountVersion::of($user->fresh())] + $name + ['honorific_prefix' => 'Dr.', 'credentials' => 'EdD'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Dr. Ana Reyes, EdD');
        $this->assertSame('EdD', Faculty::where('user_id', $id)->value('credentials'));

        $this->actingAs($user->fresh(), 'sanctum')->patchJson('/api/profile', ['expected_version' => AccountVersion::of($user->fresh())] + $name)
            ->assertOk()
            ->assertJsonPath('data.user.credentials', 'EdD');

        $this->actingAs($user->fresh(), 'sanctum')->patchJson('/api/profile', ['expected_version' => AccountVersion::of($user->fresh())] + $name + ['credentials' => null])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Dr. Ana Reyes');
        $this->assertNull(Faculty::where('user_id', $id)->value('credentials'));

        $this->actingAs($user->fresh(), 'sanctum')->patchJson('/api/profile', ['expected_version' => AccountVersion::of($user->fresh())] + $name + ['credentials' => 'PhD; drop'])
            ->assertJsonValidationErrors('credentials');
    }

    public function test_name_options_come_from_one_backend_source(): void
    {
        $this->actingAs($this->vpaa, 'sanctum')->getJson('/api/name-options')
            ->assertOk()
            ->assertExactJson([
                'honorific_prefixes' => PersonName::HONORIFIC_PREFIXES,
                'suffixes' => Faculty::NAME_SUFFIXES,
            ]);
    }

    private function createUser(array $overrides = [])
    {
        return $this->actingAs($this->vpaa, 'sanctum')->postJson('/api/user', $overrides + [
            'first_name' => 'New',
            'last_name' => 'Account',
            'username' => 'new.account',
            'email' => 'new.account@school.edu.ph',
            'role' => 'secretary',
            'department_id' => $this->department->id,
        ]);
    }

    private function updateUser(User $user, array $overrides)
    {
        return $this->actingAs($this->vpaa, 'sanctum')->putJson("/api/user/{$user->id}", $overrides + [
            'expected_version' => AccountVersion::of(User::findOrFail($user->id)),
            'first_name' => $user->first_name,
            'middle_initial' => $user->middle_initial,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => true,
            'department_id' => $user->department_id,
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
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
    }

    private function facultyPayload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'New',
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'max_units' => 18,
            'department_id' => $this->department->id,
            'status' => 'active',
        ];
    }
}
