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
 * GET /api/sections used to return every department's sections to every role,
 * to be filtered in the browser, with each row nesting its department's base64
 * logo. Department users now receive only their own department's sections.
 */
class SectionIndexScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_users_see_only_their_departments_sections_and_no_logo(): void
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $it = $this->departmentWithSection('CIT', 'BSIT 1A', $semester);
        $this->departmentWithSection('CED', 'BSED 1A', $semester);

        $secretary = $this->grantCapabilities(
            User::factory()->create(['role' => 'secretary', 'department_id' => $it->id]),
            ['schedule.view'],
        );
        $rows = $this->actingAs($secretary)->getJson('/api/sections')->assertOk()->json();

        $this->assertSame(['BSIT 1A'], array_column($rows, 'section_name'));
        $this->assertArrayNotHasKey('logo', $rows[0]['department']);
        $this->assertSame('CIT', $rows[0]['department']['department_code']);

        $vpaa = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa']), ['schedule.view']);
        $all = $this->actingAs($vpaa)->getJson('/api/sections')->assertOk()->json();

        $this->assertEqualsCanonicalizing(['BSIT 1A', 'BSED 1A'], array_column($all, 'section_name'));
    }

    private function departmentWithSection(string $code, string $sectionName, Semester $semester): Departments
    {
        $department = Departments::create([
            'department_name' => $code.' Department',
            'department_code' => $code,
            'logo' => 'data:image/png;base64,'.str_repeat('A', 2048),
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => $code.'P', 'name' => $code.' Program']);
        Sections::create([
            'section_name' => $sectionName,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        return $department;
    }
}
