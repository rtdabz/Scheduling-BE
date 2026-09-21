<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A capability the VPAA has granted must actually open the route it names.
 *
 * Faculty load, availability and the timeslot grid were gated on hardcoded role
 * names left over from before the capability system. A Program Head or Dean the
 * VPAA had granted every capability was still refused -- "access granted but
 * still locked".
 */
class GrantedCapabilityAccessTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Departments::create([
            'department_code' => 'CCS',
            'department_name' => 'College of Computer Studies',
        ]);

        $this->program = Program::create([
            'department_id' => $this->department->id,
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
        ]);
    }

    /** @param list<string>|null $capabilities */
    private function account(string $role, ?array $capabilities = null): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'department_id' => $this->department->id,
            'program_id' => $role === 'program_head' ? $this->program->id : null,
            'is_active' => true,
        ]);

        return $this->grantCapabilities($user, $capabilities);
    }

    private function instructor(?int $programId = null): Faculty
    {
        return Faculty::create([
            'first_name' => 'Ann',
            'last_name' => 'Cruz',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'department_id' => $this->department->id,
            'program_id' => $programId ?? $this->program->id,
            'status' => 'active',
        ]);
    }

    /** @return list<array{0: string}> */
    public static function grantedRoles(): array
    {
        return [['secretary'], ['program_head'], ['dean']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('grantedRoles')]
    public function test_granted_assignment_capability_opens_teaching_load_editing(string $role): void
    {
        Sanctum::actingAs($this->account($role, ['schedule.view', 'schedule.assign_instructor']));

        $this->putJson("/api/faculties/{$this->instructor()->id}", ['max_units' => 24])
            ->assertOk()
            ->assertJsonPath('max_units', 24);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('grantedRoles')]
    public function test_granted_assignment_capability_opens_availability_editing(string $role): void
    {
        Sanctum::actingAs($this->account($role, ['schedule.view', 'schedule.assign_instructor']));

        $this->putJson("/api/faculties/{$this->instructor()->id}/availabilities", [
            'availabilities' => [
                ['day_index' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ],
        ])->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('grantedRoles')]
    public function test_granted_view_capability_opens_the_timeslot_grid(string $role): void
    {
        Sanctum::actingAs($this->account($role, ['schedule.view']));

        $this->getJson('/api/timeslots')->assertOk();
    }

    public function test_account_without_the_assignment_capability_is_still_refused(): void
    {
        // A dean holds `schedule.view` by role but not instructor assignment.
        Sanctum::actingAs($this->account('dean', []));
        $faculty = $this->instructor();

        $this->putJson("/api/faculties/{$faculty->id}", ['max_units' => 24])->assertForbidden();
        $this->putJson("/api/faculties/{$faculty->id}/availabilities", ['availabilities' => []])
            ->assertForbidden();
    }

    public function test_only_the_vpaa_may_edit_roster_identity_fields(): void
    {
        $faculty = $this->instructor();

        foreach (['secretary', 'program_head', 'dean'] as $role) {
            Sanctum::actingAs($this->account($role, ['schedule.view', 'schedule.assign_instructor']));

            $this->putJson("/api/faculties/{$faculty->id}", ['first_name' => 'Renamed'])
                ->assertForbidden();
        }

        $this->assertSame('Ann', $faculty->fresh()->first_name);
    }

    public function test_a_program_head_may_not_touch_another_programs_instructor(): void
    {
        $otherProgram = Program::create([
            'department_id' => $this->department->id,
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
        ]);
        $outsider = $this->instructor($otherProgram->id);

        Sanctum::actingAs($this->account('program_head', ['schedule.view', 'schedule.assign_instructor']));

        $this->putJson("/api/faculties/{$outsider->id}", ['max_units' => 24])->assertNotFound();
        $this->putJson("/api/faculties/{$outsider->id}/availabilities", ['availabilities' => []])
            ->assertNotFound();
    }
}
