<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseTeachingAssignment;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A major belongs to the department that offers it and, once the course names one,
 * to a program inside that department. Only instructors of that department and
 * program may teach it — service and minor courses keep their delegated behaviour.
 */
class MajorInstructorRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_major_accepts_an_instructor_of_its_own_department(): void
    {
        $fixture = $this->fixture();

        $violations = $this->validate($fixture, $fixture['major'], $fixture['ownInstructor']);

        $this->assertSame([], $violations);
    }

    public function test_a_major_refuses_an_instructor_from_another_department(): void
    {
        $fixture = $this->fixture();

        $violations = $this->validate($fixture, $fixture['major'], $fixture['outsideInstructor']);

        $this->assertContains('major_faculty_department_alignment', $this->rules($violations));
    }

    public function test_a_program_bound_major_refuses_an_instructor_of_another_program(): void
    {
        $fixture = $this->fixture();
        $fixture['major']->update(['program_id' => $fixture['program']->id]);

        $violations = $this->validate($fixture, $fixture['major'], $fixture['otherProgramInstructor']);

        $this->assertContains('major_faculty_program_alignment', $this->rules($violations));
        $this->assertStringContainsString(
            'BSIT',
            collect($violations)->firstWhere('rule', 'major_faculty_program_alignment')['message'],
        );
    }

    public function test_a_program_bound_major_refuses_an_instructor_with_no_program(): void
    {
        $fixture = $this->fixture();
        $fixture['major']->update(['program_id' => $fixture['program']->id]);

        $violations = $this->validate($fixture, $fixture['major'], $fixture['ownInstructor']);

        $this->assertContains('major_faculty_program_alignment', $this->rules($violations));
    }

    public function test_a_program_bound_major_accepts_an_instructor_of_that_program(): void
    {
        $fixture = $this->fixture();
        $fixture['major']->update(['program_id' => $fixture['program']->id]);
        $fixture['ownInstructor']->update(['program_id' => $fixture['program']->id]);

        $violations = $this->validate($fixture, $fixture['major'], $fixture['ownInstructor']);

        $this->assertSame([], $violations);
    }

    public function test_a_minor_course_is_unaffected_by_the_program_restriction(): void
    {
        $fixture = $this->fixture();
        $fixture['minor']->update(['program_id' => $fixture['program']->id]);

        $violations = $this->validate($fixture, $fixture['minor'], $fixture['ownInstructor']);

        $this->assertSame([], $violations);
    }

    public function test_a_teaching_assignment_cannot_delegate_a_major_to_another_department(): void
    {
        $fixture = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);

        $this->actingAs($vpaa)
            ->postJson('/api/course-teaching-assignments', [
                'course_id' => $fixture['major']->id,
                'department_id' => $fixture['otherDepartment']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'A major course can only be taught by the department that offers it, so it cannot be assigned to another department.',
            );

        $this->assertDatabaseCount('course_teaching_assignments', 0);
    }

    public function test_a_teaching_assignment_may_still_delegate_a_minor(): void
    {
        $fixture = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);

        $this->actingAs($vpaa)
            ->postJson('/api/course-teaching-assignments', [
                'course_id' => $fixture['minor']->id,
                'department_id' => $fixture['otherDepartment']->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('course_teaching_assignments', [
            'course_id' => $fixture['minor']->id,
            'department_id' => $fixture['otherDepartment']->id,
        ]);
    }

    public function test_an_existing_cross_department_major_assignment_is_ignored(): void
    {
        $fixture = $this->fixture();

        // Written before the rule existed: the row says another department teaches
        // this major. It must not make that department's instructors eligible.
        CourseTeachingAssignment::create([
            'course_id' => $fixture['major']->id,
            'department_id' => $fixture['otherDepartment']->id,
        ]);
        \App\Services\Scheduling\SchedulingPolicy::clearCourseTeachingAssignmentCache();

        $outside = $this->validate($fixture, $fixture['major'], $fixture['outsideInstructor']);
        $own = $this->validate($fixture, $fixture['major'], $fixture['ownInstructor']);

        $this->assertContains('major_faculty_department_alignment', $this->rules($outside));
        $this->assertSame([], $own);
    }

    public function test_a_shared_minor_accepts_an_instructor_from_another_department(): void
    {
        $fixture = $this->fixture();
        // PATH FIT and the like: a minor no department owns or has been assigned to
        // teach, taught by external instructors.
        $fixture['minor']->update(['department_id' => null]);

        $violations = $this->validate($fixture, $fixture['minor'], $fixture['outsideInstructor']);

        $this->assertSame([], $violations);
    }

    public function test_a_delegated_minor_still_requires_its_assigned_department(): void
    {
        $fixture = $this->fixture();
        CourseTeachingAssignment::create([
            'course_id' => $fixture['minor']->id,
            'department_id' => $fixture['otherDepartment']->id,
        ]);
        \App\Services\Scheduling\SchedulingPolicy::clearCourseTeachingAssignmentCache();

        $refused = $this->validate($fixture, $fixture['minor'], $fixture['ownInstructor']);
        $accepted = $this->validate($fixture, $fixture['minor'], $fixture['outsideInstructor']);

        $this->assertContains('service_subject_faculty_department_alignment', $this->rules($refused));
        $this->assertSame([], $accepted);
    }

    public function test_a_minor_course_never_keeps_a_program(): void
    {
        $fixture = $this->fixture();
        $secretary = User::factory()->create([
            'role' => 'secretary',
            'department_id' => $fixture['department']->id,
        ]);

        // The restriction is for majors only, so a minor is not program-bound even
        // when a program is sent for it.
        $this->actingAs($secretary)
            ->patchJson("/api/courses/{$fixture['minor']->id}", ['program_id' => $fixture['program']->id])
            ->assertOk();

        $this->assertNull($fixture['minor']->refresh()->program_id);

        // Turning a major into a minor drops the program it was tied to.
        $fixture['major']->update(['program_id' => $fixture['program']->id]);

        $this->actingAs($secretary)
            ->patchJson("/api/courses/{$fixture['major']->id}", ['course_category' => 'minor'])
            ->assertOk();

        $this->assertNull($fixture['major']->refresh()->program_id);
    }

    public function test_the_assignment_workspace_names_the_program_it_requires(): void
    {
        $fixture = $this->fixture();
        $fixture['major']->update(['program_id' => $fixture['program']->id]);
        $secretary = User::factory()->create([
            'role' => 'secretary',
            'department_id' => $fixture['department']->id,
        ]);
        $schedule = Schedule::create([
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $fixture['major']->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'approved',
        ]);

        $this->actingAs($secretary)
            ->patchJson("/api/instructor-assignments/{$schedule->id}", [
                'faculty_id' => $fixture['otherProgramInstructor']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This major belongs to the BSIT program, so only instructors of that program can be assigned.',
            );

        $this->assertNull($schedule->refresh()->faculty_id);

        $fixture['ownInstructor']->update(['program_id' => $fixture['program']->id]);

        $this->actingAs($secretary)
            ->patchJson("/api/instructor-assignments/{$schedule->id}", [
                'faculty_id' => $fixture['ownInstructor']->id,
            ])
            ->assertOk();

        $this->assertSame($fixture['ownInstructor']->id, $schedule->refresh()->faculty_id);
    }

    /**
     * Acts as the VPAA because an instructor's program is a roster field: the
     * secretary's faculty writes are narrowed to the load allowances, so a
     * secretary sending program_id is refused before this rule is ever reached.
     */
    public function test_faculty_program_must_belong_to_the_faculty_department(): void
    {
        $fixture = $this->fixture();
        $vpaa = User::factory()->create([
            'role' => 'vpaa',
            'department_id' => $fixture['department']->id,
        ]);
        $outsideProgram = Program::create([
            'department_id' => $fixture['otherDepartment']->id,
            'code' => 'BSCE',
            'name' => 'Civil Engineering',
        ]);

        $this->actingAs($vpaa)
            ->patchJson("/api/faculties/{$fixture['ownInstructor']->id}", [
                'program_id' => $outsideProgram->id,
            ])
            ->assertStatus(422);

        $this->actingAs($vpaa)
            ->patchJson("/api/faculties/{$fixture['ownInstructor']->id}", [
                'program_id' => $fixture['program']->id,
            ])
            ->assertOk();

        $this->assertSame($fixture['program']->id, $fixture['ownInstructor']->refresh()->program_id);
    }

    public function test_course_program_must_belong_to_the_course_department(): void
    {
        $fixture = $this->fixture();
        $secretary = User::factory()->create([
            'role' => 'secretary',
            'department_id' => $fixture['department']->id,
        ]);
        $outsideProgram = Program::create([
            'department_id' => $fixture['otherDepartment']->id,
            'code' => 'BSEE',
            'name' => 'Electrical Engineering',
        ]);

        $this->actingAs($secretary)
            ->patchJson("/api/courses/{$fixture['major']->id}", ['program_id' => $outsideProgram->id])
            ->assertStatus(422);

        $this->actingAs($secretary)
            ->patchJson("/api/courses/{$fixture['major']->id}", ['program_id' => $fixture['program']->id])
            ->assertOk();

        $this->assertSame($fixture['program']->id, $fixture['major']->refresh()->program_id);
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<int, array<string, mixed>>
     */
    private function validate(array $fixture, Course $course, Faculty $faculty): array
    {
        $schedule = Schedule::create([
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $course->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ]);

        $violations = app(RuleEngine::class)->validate(array_merge($schedule->toArray(), [
            'faculty_id' => $faculty->id,
            'ignore_schedule_id' => $schedule->id,
        ]));

        $schedule->delete();

        return $violations;
    }

    /**
     * @param array<int, array<string, mixed>> $violations
     * @return array<int, string>
     */
    private function rules(array $violations): array
    {
        return array_map(static fn (array $violation): string => (string) $violation['rule'], $violations);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'CIT']);
        $otherDepartment = Departments::create(['department_name' => 'Engineering', 'department_code' => 'COE']);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);
        $otherProgram = Program::create([
            'department_id' => $department->id,
            'code' => 'BSCS',
            'name' => 'Computer Science',
        ]);

        return [
            'department' => $department,
            'otherDepartment' => $otherDepartment,
            'term' => $term,
            'program' => $program,
            'otherProgram' => $otherProgram,
            'room' => Rooms::create([
                'room_code' => 'CIT 101',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
            ]),
            'section' => Sections::create([
                'section_name' => 'BSIT 1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]),
            'major' => $this->course('IT 101', 'major', $department->id),
            'minor' => $this->course('SOC 101', 'minor', $department->id),
            'ownInstructor' => $this->instructor('Own', $department->id, null),
            'otherProgramInstructor' => $this->instructor('OtherProgram', $department->id, $otherProgram->id),
            'outsideInstructor' => $this->instructor('Outside', $otherDepartment->id, null),
        ];
    }

    private function course(string $code, string $category, int $departmentId): Course
    {
        return Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
            'lecture_hours' => 1,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => $category,
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $departmentId,
            'status' => 'active',
        ]);
    }

    private function instructor(string $name, int $departmentId, ?int $programId): Faculty
    {
        return Faculty::create([
            'first_name' => $name,
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'max_units' => 18,
            'department_id' => $departmentId,
            'program_id' => $programId,
            'status' => 'active',
        ]);
    }
}
