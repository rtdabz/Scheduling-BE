<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumCreationTest extends TestCase
{
    use RefreshDatabase;

    private function secretary(): User
    {
        $secretary = User::factory()->create(['role' => 'secretary']);
        $secretary->assignRole('secretary');

        return $secretary->fresh();
    }

    public function test_a_new_curriculum_starts_active(): void
    {
        $this->actingAs($this->secretary())->postJson('/api/curriculum', [
            'name' => 'BS Business Administration',
            'code' => 'BSBA-2025',
            'effective_school_year' => '2025-2026',
        ])->assertCreated()->assertJsonPath('status', 'active');
    }

    public function test_a_new_curriculum_belongs_to_the_authors_department(): void
    {
        $own = Departments::create(['department_name' => 'Business', 'department_code' => 'CBA']);
        $other = Departments::create(['department_name' => 'Education', 'department_code' => 'EDUC']);
        $secretary = $this->secretary();
        $secretary->update(['department_id' => $own->id]);

        $this->actingAs($secretary->fresh())->postJson('/api/curriculum', [
            'name' => 'BS Business Administration',
            'code' => 'BSBA-2025',
            'effective_school_year' => '2025-2026',
            'department_id' => $other->id,
        ])->assertCreated()->assertJsonPath('department_id', $own->id);
    }

    public function test_a_program_head_authors_their_own_programs_curriculum_only(): void
    {
        $department = Departments::create(['department_name' => 'Business', 'department_code' => 'CBA']);
        $own = Program::create(['department_id' => $department->id, 'code' => 'BSBA', 'name' => 'Business Administration']);
        $other = Program::create(['department_id' => $department->id, 'code' => 'BSA', 'name' => 'Accountancy']);
        $head = User::factory()->create([
            'role' => 'program_head', 'department_id' => $department->id, 'program_id' => $own->id,
        ])->fresh();

        $this->assertTrue($head->hasCapability('curriculum.manage'));

        // A requested program is ignored; the curriculum is the head's own program's.
        $this->actingAs($head)->postJson('/api/curriculum', [
            'name' => 'BS Business Administration',
            'code' => 'BSBA-2025',
            'effective_school_year' => '2025-2026',
            'program_id' => $other->id,
        ])->assertCreated()->assertJsonPath('program_id', $own->id);

        $sibling = Curriculum::create([
            'name' => 'BS Accountancy', 'code' => 'BSA-2025', 'effective_school_year' => '2025-2026',
            'status' => 'active', 'department_id' => $department->id, 'program_id' => $other->id,
        ]);

        $this->actingAs($head)->putJson('/api/curriculum/'.$sibling->id, ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($head)->patchJson('/api/curriculum/'.$sibling->id.'/status', ['status' => 'deactivated'])->assertForbidden();
    }

    public function test_the_school_year_must_be_two_consecutive_years(): void
    {
        $secretary = $this->secretary();

        foreach (['2025', '2025-2027', '2026-2025', '25-26'] as $index => $year) {
            $this->actingAs($secretary)->postJson('/api/curriculum', [
                'name' => 'BS Business Administration',
                'code' => 'BSBA-BAD-'.$index,
                'effective_school_year' => $year,
            ])->assertUnprocessable()->assertJsonValidationErrors('effective_school_year');
        }
    }
}
