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
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Instructors for a program's major courses are assigned by its Program Head. The department
 * office (Secretary or Director) keeps that work only for programs without an active Program
 * Head. Minor courses keep the existing workflow.
 */
class MajorInstructorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public static function officeRoles(): array
    {
        return [['secretary'], ['director']];
    }

    #[DataProvider('officeRoles')]
    public function test_office_cannot_assign_a_major_of_a_program_with_a_program_head(string $role): void
    {
        $f = $this->fixture($role);

        $this->actingAs($f['office'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['majorA']->id], 'faculty_id' => $f['facultyA']->id,
            ]]])
            ->assertForbidden()
            ->assertJsonPath('message', ScheduleAuthorizationService::MAJOR_INSTRUCTOR_FORBIDDEN_MESSAGE);

        $this->actingAs($f['office'])
            ->patchJson("/api/instructor-assignments/{$f['majorA']->id}", ['faculty_id' => $f['facultyA']->id])
            ->assertForbidden();

        $this->assertNull($f['majorA']->refresh()->faculty_id);
    }

    #[DataProvider('officeRoles')]
    public function test_office_cannot_clear_a_major_of_a_program_with_a_program_head(string $role): void
    {
        $f = $this->fixture($role);
        $f['majorA']->update(['faculty_id' => $f['facultyA']->id]);

        $this->actingAs($f['office'])
            ->postJson('/api/instructor-assignments/clear', ['section_ids' => [$f['sectionA']->id]])
            ->assertStatus(422);

        $this->assertSame($f['facultyA']->id, (int) $f['majorA']->refresh()->faculty_id);
    }

    #[DataProvider('officeRoles')]
    public function test_office_assigns_a_major_of_a_program_without_a_program_head(string $role): void
    {
        $f = $this->fixture($role);

        $this->actingAs($f['office'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['majorB']->id], 'faculty_id' => $f['facultyB']->id,
            ]]])
            ->assertOk();

        $this->assertSame($f['facultyB']->id, (int) $f['majorB']->refresh()->faculty_id);
    }

    #[DataProvider('officeRoles')]
    public function test_office_keeps_minor_courses_in_a_program_with_a_program_head(string $role): void
    {
        $f = $this->fixture($role);

        $this->actingAs($f['office'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['minorA']->id], 'faculty_id' => $f['facultyA']->id,
            ]]])
            ->assertOk();

        $this->assertSame($f['facultyA']->id, (int) $f['minorA']->refresh()->faculty_id);
    }

    #[DataProvider('officeRoles')]
    public function test_a_department_level_major_follows_its_sections_program(string $role): void
    {
        $f = $this->fixture($role);
        // Majors are often recorded with no program on the course itself.
        $f['majorA']->course->update(['program_id' => null]);
        $f['majorB']->course->update(['program_id' => null]);

        $this->actingAs($f['office'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['majorA']->id], 'faculty_id' => $f['facultyA']->id,
            ]]])
            ->assertForbidden()
            ->assertJsonPath('message', ScheduleAuthorizationService::MAJOR_INSTRUCTOR_FORBIDDEN_MESSAGE);

        // Program B has no Program Head, so the office keeps it.
        $this->actingAs($f['office'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['majorB']->id], 'faculty_id' => $f['facultyB']->id,
            ]]])
            ->assertOk();
    }

    public function test_program_head_assigns_its_own_major(): void
    {
        $f = $this->fixture('secretary');

        $this->actingAs($f['headA'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['majorA']->id], 'faculty_id' => $f['facultyA']->id,
            ]]])
            ->assertOk();

        $this->assertSame($f['facultyA']->id, (int) $f['majorA']->refresh()->faculty_id);
    }

    public function test_office_regains_the_major_once_the_program_head_is_deactivated(): void
    {
        $f = $this->fixture('secretary');
        $f['headA']->update(['is_active' => false]);

        $this->actingAs($f['office'])
            ->patchJson('/api/schedules/batch-faculty', ['assignments' => [[
                'schedule_ids' => [$f['majorA']->id], 'faculty_id' => $f['facultyA']->id,
            ]]])
            ->assertOk();
    }

    private function fixture(string $officeRole): array
    {
        $department = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        $programA = Program::create(['department_id' => $department->id, 'code' => 'BAPSY', 'name' => 'Psychology']);
        $programB = Program::create(['department_id' => $department->id, 'code' => 'BAENG', 'name' => 'English']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create(['room_code' => 'CAS101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        $course = fn (string $code, string $category, ?Program $program): Course => Course::create([
            'course_code' => $code,
            'course_name' => $code,
            'lecture_hours' => 1,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => $category,
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program?->id,
            'status' => 'active',
        ]);
        $section = fn (Program $program, string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $sectionA = $section($programA, 'PSY1A');
        $sectionB = $section($programB, 'ENG1A');
        $row = fn (Sections $section, Course $course, string $day): Schedule => Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'program_id' => $section->program_id,
            'day' => $day,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'approved',
        ]);
        $faculty = fn (Program $program, string $name): Faculty => Faculty::create([
            'first_name' => $name,
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'department_id' => $department->id,
            'program_id' => $program->id,
            'status' => 'active',
        ]);

        return [
            'sectionA' => $sectionA,
            'majorA' => $row($sectionA, $course('PSY101', 'major', $programA), 'Monday'),
            'minorA' => $row($sectionA, $course('GE101', 'minor', null), 'Tuesday'),
            'majorB' => $row($sectionB, $course('ENG101', 'major', $programB), 'Wednesday'),
            'facultyA' => $faculty($programA, 'Psy'),
            'facultyB' => $faculty($programB, 'Eng'),
            'office' => $this->grantCapabilities(User::factory()->create(['role' => $officeRole, 'department_id' => $department->id])),
            'headA' => $this->grantCapabilities(User::factory()->create([
                'role' => 'program_head',
                'department_id' => $department->id,
                'program_id' => $programA->id,
            ])),
        ];
    }
}
