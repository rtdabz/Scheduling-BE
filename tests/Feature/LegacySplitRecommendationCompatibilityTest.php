<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacySplitRecommendationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_preview_retains_the_exact_operation_without_saving(): void
    {
        [$user, $operation] = $this->fixture();

        $this->actingAs($user)->postJson('/api/schedules/batch/validate-splits', ['operations' => [$operation]])
            ->assertOk()->assertExactJson([
                'status' => 'ok',
                'message' => 'All split sessions validated successfully.',
                'operations' => [$operation],
            ]);

        $this->assertSame(0, Schedule::query()->count());
        $this->assertDatabaseCount('schedule_history_versions', 0);
        $this->assertDatabaseCount('scheduling_audit_logs', 0);
    }

    public function test_inactive_course_retains_the_conflict_status_and_violations_without_saving(): void
    {
        [$user, $operation] = $this->fixture();
        Course::query()->whereKey($operation['course_id'])->update(['status' => 'inactive']);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch/validate-splits', ['operations' => [$operation]])
            ->assertUnprocessable()->assertJsonPath('status', 'conflict')
            ->assertJsonPath('message', 'One or more split sessions could not be scheduled conflict-free.')
            ->assertJsonStructure(['status', 'message', 'violations' => [['rule', 'operation_index', 'course_code', 'day', 'start_time', 'end_time']]]);

        $this->assertNotEmpty($response->json('violations'));
        $this->assertContains('subject_active', array_column($response->json('violations'), 'rule'));
        $this->assertSame(0, Schedule::query()->count());
        $this->assertDatabaseCount('scheduling_audit_logs', 0);
    }

    /** @return array{User, array<string, mixed>} */
    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'Department A', 'department_code' => 'DEPA']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'PA', 'name' => 'Program A']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create(['room_code' => 'A101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $course = Course::create([
            'course_code' => 'A101', 'course_name' => 'Department A Course', 'lecture_hours' => 1, 'lab_hours' => 0,
            'units' => 1, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1',
            'semester' => '1st', 'department_id' => $department->id, 'status' => 'active',
        ]);
        $section = Sections::create([
            'section_name' => 'A1', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id,
            'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return [$user, [
            'semester_id' => $semester->id, 'department_id' => $department->id, 'section_id' => $section->id,
            'course_id' => $course->id, 'room_id' => $room->id, 'day' => 'Wednesday',
            'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site',
        ]];
    }
}
