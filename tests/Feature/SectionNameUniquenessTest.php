<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A section name identifies a cohort within its department for a semester, so
 * two live sections there cannot share one. Nothing enforced this, and a second
 * "BSIT 1A" could be created beside the first.
 */
class SectionNameUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_section_cannot_reuse_a_live_name_in_the_same_department_and_semester(): void
    {
        $fixture = $this->fixture();
        $this->createSection($fixture, 'BSIT 1A');

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections', $this->payload($fixture, 'BSIT 1A'))
            ->assertStatus(422)
            ->assertJsonPath('errors.section_name.0', 'BSIT 1A already exists in this department for the active semester.');

        $this->assertSame(1, Sections::where('section_name', 'BSIT 1A')->count());
    }

    public function test_the_match_ignores_case_and_spacing(): void
    {
        $fixture = $this->fixture();
        $this->createSection($fixture, 'BSIT 1A');

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections', $this->payload($fixture, '  bsit   1a '))
            ->assertStatus(422);
    }

    public function test_names_are_stored_normalised(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections', $this->payload($fixture, ' bsit   1b'))
            ->assertCreated()
            ->assertJsonPath('section_name', 'BSIT 1B');
    }

    public function test_another_department_may_use_the_same_name(): void
    {
        $fixture = $this->fixture();
        $other = Departments::create(['department_name' => 'Business', 'department_code' => 'CBA']);
        Sections::create([
            'section_name' => 'BSIT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $other->id, 'semester_id' => $fixture['semester']->id, 'status' => 'active',
        ]);

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections', $this->payload($fixture, 'BSIT 1A'))
            ->assertCreated();
    }

    public function test_a_batch_rejects_existing_names_and_repeats_within_itself(): void
    {
        $fixture = $this->fixture();
        $this->createSection($fixture, 'BSIT 1A');

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections/batch', ['sections' => [
                $this->payload($fixture, 'BSIT 1A'),
                $this->payload($fixture, 'BSIT 1B'),
                $this->payload($fixture, 'bsit 1b'),
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sections.0.section_name', 'sections.2.section_name'])
            ->assertJsonMissingValidationErrors(['sections.1.section_name']);

        // Nothing in a refused batch is written.
        $this->assertFalse(Sections::where('section_name', 'BSIT 1B')->exists());
    }

    public function test_renaming_onto_a_taken_name_is_refused_but_keeping_ones_own_name_is_not(): void
    {
        $fixture = $this->fixture();
        $this->createSection($fixture, 'BSIT 1A');
        $second = $this->createSection($fixture, 'BSIT 1B');

        $this->actingAs($fixture['secretary'])
            ->patchJson("/api/sections/{$second->id}", ['section_name' => 'BSIT 1A'])
            ->assertStatus(422);

        $this->actingAs($fixture['secretary'])
            ->patchJson("/api/sections/{$second->id}", ['section_name' => 'BSIT 1B', 'year_level' => '2'])
            ->assertOk();
    }

    public function test_an_archived_name_is_free_again_but_cannot_be_restored_over_its_replacement(): void
    {
        $fixture = $this->fixture();
        $archived = $this->createSection($fixture, 'BSIT 1A');
        $archived->delete();

        $this->actingAs($fixture['secretary'])
            ->postJson('/api/sections', $this->payload($fixture, 'BSIT 1A'))
            ->assertCreated();

        $this->actingAs($fixture['vpaa'], 'sanctum')
            ->postJson("/api/archives/sections/{$archived->id}/restore")
            ->assertStatus(422);

        $this->assertSoftDeleted('sections', ['id' => $archived->id]);
    }

    /** @return array<string, mixed> */
    private function payload(array $fixture, string $name): array
    {
        return [
            'section_name' => $name,
            'year_level' => '1',
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
        ];
    }

    private function createSection(array $fixture, string $name): Sections
    {
        return Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $fixture['department']->id,
            'program_id' => $fixture['program']->id,
            'semester_id' => $fixture['semester']->id,
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'program' => $program,
            'secretary' => $this->grantCapabilities(
                User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
            ),
            'vpaa' => User::factory()->create(['role' => 'vpaa']),
        ];
    }
}
