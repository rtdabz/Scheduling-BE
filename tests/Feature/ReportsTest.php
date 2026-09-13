<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The printed Department Schedule and Teaching Load are official records, so
 * only VPAA-approved schedules reach them, and a section prints only once every
 * one of its meetings has cleared approval.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_fully_approved_sections_are_reported(): void
    {
        $f = $this->fixture();
        $approved = $this->section($f, 'IT-1A');
        $partial = $this->section($f, 'IT-1B');
        $draft = $this->section($f, 'IT-1C');

        $this->schedule($f, $approved, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);
        $this->schedule($f, $approved, ['status' => 'faculty_assignment', 'day' => 'Tuesday']);
        $this->schedule($f, $partial, ['status' => 'approved']);
        $this->schedule($f, $partial, ['status' => 'revision', 'day' => 'Tuesday']);
        $this->schedule($f, $draft, ['status' => 'draft']);

        $this->actingAs($f['secretary'])
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.complete_section_count', 1)
            ->assertJsonPath('departments.0.instructor_count', 1)
            ->assertJsonPath('departments.0.programs.0.code', 'BSIT')
            ->assertJsonPath('departments.0.programs.0.complete_section_count', 1);

        $response = $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_name', 'IT-1A')
            ->assertJsonCount(1, 'faculties');

        $statuses = collect($response->json('schedules'))->pluck('status')->unique()->sort()->values()->all();
        $this->assertSame(['faculty_assignment', 'finalized'], $statuses);
    }

    public function test_the_vpaa_account_is_returned_as_a_signatory(): void
    {
        $f = $this->fixture();
        User::factory()->create(['role' => 'vpaa', 'name' => 'Current VPAA', 'department_id' => null]);

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Current VPAA', 'role' => 'vpaa']);
    }

    public function test_department_staff_cannot_print_another_department(): void
    {
        $f = $this->fixture();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$other->id}")
            ->assertForbidden();
    }

    public function test_vpaa_sees_every_department(): void
    {
        $f = $this->fixture();
        $other = Departments::create(['department_name' => 'Other', 'department_code' => 'OTH']);
        $vpaa = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa', 'department_id' => null]));

        $this->actingAs($vpaa)
            ->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(2, 'departments');

        $this->actingAs($vpaa)
            ->getJson("/api/reports/departments/{$other->id}")
            ->assertOk();
    }

    public function test_program_filter_limits_instructors_and_sections(): void
    {
        $f = $this->fixture();
        $otherProgram = Program::create(['department_id' => $f['department']->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $csFaculty = Faculty::create([
            'first_name' => 'Cs', 'last_name' => 'Teacher', 'employment_type' => 'full-time', 'max_units' => 18,
            'department_id' => $f['department']->id, 'program_id' => $otherProgram->id, 'status' => 'active',
        ]);
        $itSection = $this->section($f, 'IT-1A');
        $csSection = $this->section($f, 'CS-1A', $otherProgram->id);
        $this->schedule($f, $itSection, ['status' => 'finalized', 'faculty_id' => $f['faculty']->id]);
        $this->schedule($f, $csSection, ['status' => 'finalized', 'faculty_id' => $csFaculty->id]);

        $this->actingAs($f['secretary'])
            ->getJson("/api/reports/departments/{$f['department']->id}?program_id={$f['program']->id}")
            ->assertOk()
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_name', 'IT-1A')
            ->assertJsonCount(1, 'faculties')
            ->assertJsonPath('faculties.0.id', $f['faculty']->id);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);

        return [
            'department' => $department,
            'program' => $program,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'IT101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create([
                'course_code' => 'IT101', 'course_name' => 'Intro', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
                'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'It', 'last_name' => 'Teacher', 'employment_type' => 'full-time', 'max_units' => 18,
                'department_id' => $department->id, 'program_id' => $program->id, 'status' => 'active',
            ]),
            'secretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    /** @param array<string, mixed> $f */
    private function section(array $f, string $name, ?int $programId = null): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $f['department']->id, 'program_id' => $programId ?? $f['program']->id,
            'semester_id' => $f['semester']->id, 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $f */
    private function schedule(array $f, Sections $section, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $f['semester']->id,
            'section_id' => $section->id,
            'course_id' => $f['course']->id,
            'room_id' => $f['room']->id,
            'department_id' => $f['department']->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'mode' => 'on-site',
        ], $overrides));
    }
}
