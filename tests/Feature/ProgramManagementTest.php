<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_vpaa_can_create_update_and_archive_a_program(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create([
            'department_name' => 'College of Education',
            'department_code' => 'CED',
        ]);

        $created = $this->actingAs($vpaa, 'sanctum')->postJson('/api/programs', [
            'department_id' => $department->id,
            'cluster' => 'BSEd',
            'code' => 'BSED-ENG',
            'name' => 'Major in English',
        ])->assertCreated()->json('data');

        $this->actingAs($vpaa, 'sanctum')->getJson('/api/departments')
            ->assertOk()
            ->assertJsonPath('0.programs.0.code', 'BSED-ENG');

        $this->actingAs($vpaa, 'sanctum')->patchJson("/api/programs/{$created['id']}", [
            'cluster' => 'BSEd',
            'code' => 'BSED-FIL',
            'name' => 'Major in Filipino',
        ])->assertOk()->assertJsonPath('data.name', 'Major in Filipino');

        $this->actingAs($vpaa, 'sanctum')->deleteJson("/api/programs/{$created['id']}")
            ->assertOk();

        $this->assertSoftDeleted('programs', ['id' => $created['id']]);
    }

    public function test_program_code_must_be_unique_within_a_department(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create([
            'department_name' => 'College of Arts and Sciences',
            'department_code' => 'CAS',
        ]);

        Program::create([
            'department_id' => $department->id,
            'cluster' => 'BA',
            'code' => 'BA-ENG',
            'name' => 'English',
        ]);

        $this->actingAs($vpaa, 'sanctum')->postJson('/api/programs', [
            'department_id' => $department->id,
            'code' => 'BA-ENG',
            'name' => 'Another English Program',
        ])->assertUnprocessable();
    }

    public function test_program_name_is_optional(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = Departments::create([
            'department_name' => 'College of Education',
            'department_code' => 'CED',
        ]);

        $created = $this->actingAs($vpaa, 'sanctum')->postJson('/api/programs', [
            'department_id' => $department->id,
            'cluster' => 'Bachelor of Secondary Education',
            'code' => 'BSED',
        ])->assertCreated()->json('data');

        $this->assertNull($created['name']);
        $this->assertDatabaseHas('programs', ['id' => $created['id'], 'name' => null]);
    }

    public function test_program_head_must_use_a_program_from_the_selected_department(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $education = Departments::create(['department_name' => 'Education', 'department_code' => 'EDU']);
        $arts = Departments::create(['department_name' => 'Arts', 'department_code' => 'ART']);
        $program = Program::create([
            'department_id' => $arts->id,
            'cluster' => 'BA',
            'code' => 'BA-ART',
            'name' => 'Arts',
        ]);

        $this->actingAs($vpaa, 'sanctum')->postJson('/api/user', [
            'name' => 'Cross Department Head',
            'username' => 'cross.head',
            'email' => 'cross.head@example.com',
            'password' => 'StrongPass123',
            'role' => 'program_head',
            'department_id' => $education->id,
            'program_id' => $program->id,
        ])->assertUnprocessable();
    }

    public function test_program_head_faculty_profile_inherits_the_assigned_program(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $education = Departments::create(['department_name' => 'Education', 'department_code' => 'CED']);
        $program = Program::create([
            'department_id' => $education->id,
            'code' => 'BPED',
            'name' => 'Physical Education',
        ]);

        $response = $this->actingAs($vpaa, 'sanctum')->postJson('/api/user', [
            'name' => 'BPED Program Head',
            'username' => 'bped.head',
            'email' => 'bped.head@example.com',
            'password' => 'StrongPass123',
            'role' => 'program_head',
            'department_id' => $education->id,
            'program_id' => $program->id,
        ])->assertCreated();

        $faculty = Faculty::where('user_id', $response->json('data.id'))->firstOrFail();
        $this->assertSame($education->id, (int) $faculty->department_id);
        $this->assertSame($program->id, (int) $faculty->program_id);
    }
}
