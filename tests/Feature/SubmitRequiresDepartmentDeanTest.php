<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Submitting hands the department's schedules to its Dean. With no Dean the
 * submission still succeeded and the schedules parked at "submitted" with nobody
 * able to approve or return them -- recoverable only by withdrawing.
 */
class SubmitRequiresDepartmentDeanTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Submission unavailable. Please assign a Department Dean before submitting the schedule.';

    public function test_submission_is_blocked_when_the_department_has_no_dean(): void
    {
        $context = $this->scaffold();

        $response = $this->actingAs($context['secretary'])
            ->postJson("/api/departments/{$context['department']->id}/submit-schedules");

        $response->assertStatus(422)
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('error_code', 'department_dean_missing');

        $this->assertSame(
            'completed',
            Schedule::query()->first()->status,
            'A blocked submission must leave the schedules untouched.',
        );
    }

    public function test_submission_proceeds_once_a_dean_is_assigned(): void
    {
        $context = $this->scaffold();
        $this->dean($context['department']);

        $response = $this->actingAs($context['secretary'])
            ->postJson("/api/departments/{$context['department']->id}/submit-schedules");

        $this->assertNotSame(422, $response->status(), 'An assigned Dean must clear the gate.');
        $this->assertNotSame(self::MESSAGE, $response->json('message'));
    }

    public function test_an_inactive_dean_does_not_count(): void
    {
        $context = $this->scaffold();
        $this->dean($context['department'], ['is_active' => false]);

        $this->actingAs($context['secretary'])
            ->postJson("/api/departments/{$context['department']->id}/submit-schedules")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'department_dean_missing');
    }

    public function test_a_dean_of_another_department_does_not_count(): void
    {
        $context = $this->scaffold();
        $other = Departments::create(['department_name' => 'College of Business', 'department_code' => 'CBA']);
        $this->dean($other);

        $this->actingAs($context['secretary'])
            ->postJson("/api/departments/{$context['department']->id}/submit-schedules")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'department_dean_missing');
    }

    public function test_the_status_endpoint_reports_whether_a_dean_exists(): void
    {
        $context = $this->scaffold();

        $this->actingAs($context['secretary'])
            ->getJson("/api/departments/{$context['department']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('has_dean', false);

        $this->dean($context['department']);

        $this->actingAs($context['secretary'])
            ->getJson("/api/departments/{$context['department']->id}/schedule-status")
            ->assertOk()
            ->assertJsonPath('has_dean', true);
    }

    /** @param array<string, mixed> $attributes */
    private function dean(Departments $department, array $attributes = []): User
    {
        return $this->grantCapabilities(User::factory()->create(array_merge([
            'role' => 'dean',
            'department_id' => $department->id,
            'is_active' => true,
        ], $attributes)));
    }

    /** @return array{department: Departments, secretary: User} */
    private function scaffold(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id,
            'semester_id' => $semester->id, 'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 101', 'course_name' => 'Programming 1',
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);

        Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => null,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'mode' => 'online',
            'status' => 'completed',
        ]);

        $secretary = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]));

        return ['department' => $department, 'secretary' => $secretary];
    }
}
