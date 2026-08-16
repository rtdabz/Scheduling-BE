<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use App\Services\Scheduling\CSPSolver;
use App\Services\Scheduling\DepartmentSchedulingAuditService;
use App\Services\Scheduling\ScheduleRequirementBuilderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentSchedulingPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_department_generates_lecture_course_without_laboratory_room(): void
    {
        [$term, $department, $section, $course] = $this->createBase('BA', 'Business Administration', 'standard');
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $response = $this->actingAs(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]))->postJson('/api/schedule-recommendations/preview', [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
        ]);

        $response->assertOk();
        $this->assertSame('standard', $response->json('department_profile'));
    }

    public function test_preflight_uses_the_active_curriculum_period_instead_of_global_course_metadata(): void
    {
        [$term, $department, $section, $course] = $this->createBase('BA', 'Business Administration', 'standard');
        $course->update([
            'year_level' => '2',
            'semester' => '2nd',
        ]);
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $response = $this->actingAs(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]))->postJson('/api/schedule-recommendations/preview', [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
        ]);

        $response->assertOk();
        $this->assertSame('standard', $response->json('department_profile'));
    }

    public function test_standard_department_does_not_use_an_available_laboratory_for_a_lecture_course(): void
    {
        [$term, $department, $section, $course] = $this->createBase('BA', 'Business Administration', 'standard');
        $this->attachCourse($department, $course, $section);
        $lecture = Rooms::create([
            'room_code' => 'BA 101',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        Rooms::create([
            'room_code' => 'BA Lab',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => true,
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $response = $this->actingAs(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]))->postJson('/api/schedule-recommendations/preview', [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
            'max_solutions' => 3,
        ]);

        $response->assertOk();
        foreach ($response->json('recommendations') as $recommendation) {
            foreach ($recommendation['schedules'] as $schedule) {
                $this->assertSame($lecture->id, $schedule['room_id']);
            }
        }
    }

    public function test_standard_department_rejects_laboratory_course_before_solver(): void
    {
        [$term, $department, $section, $course] = $this->createBase('BA', 'Business Administration', 'standard');
        $course->update([
            'course_code' => 'BA LAB 101',
            'lab_hours' => 1,
            'room_type_required' => 'laboratory',
        ]);
        $this->attachCourse($department, $course, $section);
        $room = Rooms::create([
            'room_code' => 'BA 102',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $existing = Schedule::create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '07:00:00',
            'end_time' => '10:00:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);

        $response = $this->actingAs(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]))->postJson('/api/schedule-recommendations/preview', [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'schedule_generation_preflight_failed')
            ->assertJsonPath('department_profile', 'standard')
            ->assertJsonPath('issues.0.code', 'department_profile_mismatch');
        $this->assertDatabaseHas('schedules', ['id' => $existing->id]);
    }

    public function test_standard_department_reports_missing_lecture_room(): void
    {
        [$term, $department, $section, $course] = $this->createBase('EDUC', 'Education', 'standard');
        $this->attachCourse($department, $course, $section);

        $response = $this->actingAs(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]))->postJson('/api/schedule-recommendations/preview', [
            'section_id' => $section->id,
            'course_ids' => [$course->id],
            'mode' => 'on-site',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'schedule_generation_preflight_failed')
            ->assertJsonPath('issues.0.code', 'missing_lecture_room');
    }

    public function test_standard_solver_reports_an_actionable_empty_room_domain(): void
    {
        [$term, $department, $section, $course] = $this->createBase('BA', 'Business Administration', 'standard');
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA Lab',
            'building' => 'Building 1',
            'room_type' => 'laboratory',
            'allow_lecture_usage' => true,
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $requirements = app(ScheduleRequirementBuilderResolver::class)->build($section, [$course->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BA 1A / BA 101 has no eligible scheduling candidates');

        app(CSPSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [$course->id],
            requirementsByCourseId: $requirements,
            maxSolutions: 1,
            maxIterations: 1000,
            timeoutSeconds: 1,
        );
    }

    public function test_department_audit_reports_profile_and_room_counts(): void
    {
        [$term, $department, $section, $course] = $this->createBase('BA', 'Business Administration', 'standard');
        $this->attachCourse($department, $course, $section);
        Rooms::create([
            'room_code' => 'BA 103',
            'building' => 'Building 1',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);

        $row = app(DepartmentSchedulingAuditService::class)->audit($department->id)[0];

        $this->assertSame('standard', $row['profile']);
        $this->assertSame(1, $row['active_course_count']);
        $this->assertSame(1, $row['available_lecture_rooms']);
        $this->assertFalse($row['profile_mismatch']);
    }

    private function createBase(string $code, string $name, string $profile): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => $name,
            'department_code' => $code,
            'scheduling_profile' => $profile,
        ]);
        $section = Sections::create([
            'section_name' => "{$code} 1A",
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => "{$code} 101",
            'course_name' => 'Foundations',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        return [$term, $department, $section, $course];
    }

    private function attachCourse(Departments $department, Course $course, Sections $section): void
    {
        $curriculum = Curriculum::create([
            'name' => "{$department->department_code} Curriculum",
            'department_id' => $department->id,
            'code' => "{$department->department_code}-2026",
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, [
            'year_level' => (int) $section->year_level,
            'semester' => 1,
        ]);
    }
}
