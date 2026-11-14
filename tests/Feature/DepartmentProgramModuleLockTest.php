<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Department modules follow the department's program records: without a program the program-bound
 * writes (curriculum, course assignment) are refused, while the receiving Cross-Department page
 * (instructor assignment) stays available. VPAA-approval gating of Course Assignment is covered by
 * CrossDepartmentApprovalGateTest. See docs/plan/department_module_locking_plan.md (frontend repo).
 */
class DepartmentProgramModuleLockTest extends TestCase
{
    use RefreshDatabase;

    private const NO_PROGRAM = 'Your department has no program yet, so this action is unavailable. Ask the VPAA to add a program to your department.';

    private Departments $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Departments::create(['department_name' => 'General Education', 'department_code' => 'GE']);
    }

    /** @return list<array{0: string}> */
    public static function officeRoles(): array
    {
        return [['secretary'], ['director']];
    }

    #[DataProvider('officeRoles')]
    public function test_without_a_program_curriculum_and_course_assignment_are_locked_but_cross_department_is_not(string $role): void
    {
        $office = User::factory()->create(['role' => $role, 'department_id' => $this->department->id]);

        $this->actingAs($office)->getJson('/api/me')->assertOk()->assertJsonPath('scheduling_ready', false);
        $this->actingAs($office)->postJson('/api/curriculum', $this->curriculum())
            ->assertForbidden()->assertJsonPath('message', self::NO_PROGRAM);
        $this->actingAs($office)->getJson('/api/course-teaching-assignments')
            ->assertForbidden()->assertJsonPath('message', self::NO_PROGRAM);

        // The receiving Cross-Department page reads and assigns through these.
        $this->actingAs($office)->getJson('/api/instructor-assignments')->assertOk();
        $this->actingAs($office)->getJson('/api/initial-data')->assertOk();
    }

    #[DataProvider('officeRoles')]
    public function test_adding_a_program_unlocks_and_removing_the_last_one_relocks(string $role): void
    {
        $office = User::factory()->create(['role' => $role, 'department_id' => $this->department->id]);
        $program = Program::create(['department_id' => $this->department->id, 'code' => 'BSGE', 'name' => 'General Studies']);

        $this->actingAs($office)->getJson('/api/me')->assertJsonPath('scheduling_ready', true);
        $this->actingAs($office)->postJson('/api/curriculum', $this->curriculum())->assertCreated();
        $this->actingAs($office)->getJson('/api/course-teaching-assignments')->assertOk();

        $program->delete();

        $this->actingAs($office)->getJson('/api/me')->assertJsonPath('scheduling_ready', false);
        $this->actingAs($office)->postJson('/api/curriculum', $this->curriculum('GE-2'))->assertForbidden();
        $this->actingAs($office)->getJson('/api/course-teaching-assignments')->assertForbidden();
        $this->actingAs($office)->getJson('/api/instructor-assignments')->assertOk();
    }

    /** @return array<string, string> */
    private function curriculum(string $code = 'GE-1'): array
    {
        return ['name' => 'General Studies', 'code' => $code, 'effective_school_year' => '2026-2027'];
    }
}
