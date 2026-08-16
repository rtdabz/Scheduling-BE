<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\SystemNotification;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentScheduleWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_can_withdraw_selected_sections_after_vpaa_approval(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $first = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
            'reviewed_by_dean' => $vpaa->id,
            'reviewed_at_dean' => now(),
            'approved_by_vpaa' => $vpaa->id,
            'approved_at_vpaa' => now(),
        ]);
        $second = $this->schedule($department, $term, $room, $course, $secondSection, [
            'status' => 'faculty_assignment',
            'reviewed_by_dean' => $vpaa->id,
            'reviewed_at_dean' => now(),
            'approved_by_vpaa' => $vpaa->id,
            'approved_at_vpaa' => now(),
        ]);

        $response = $this->actingAs($secretary)->postJson("/api/departments/{$department->id}/withdraw-submission", [
            'section_ids' => [$firstSection->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('withdrawal_stage', 'vpaa_approved')
            ->assertJsonPath('sections_unlocked', 1);

        $this->assertSame('revision', $first->refresh()->status);
        $this->assertSame('completed', $second->refresh()->status);
        $this->assertDatabaseHas('schedules', [
            'id' => $first->id,
            'reviewed_by_dean' => null,
            'approved_by_vpaa' => null,
            'approved_at_vpaa' => null,
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $vpaa->id,
            'type' => 'schedule_withdrawn',
        ]);
    }

    public function test_finalized_schedule_cannot_be_withdrawn(): void
    {
        [$department, $term, $room, $course, $firstSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $schedule = $this->schedule($department, $term, $room, $course, $firstSection, ['status' => 'finalized']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", ['section_ids' => [$firstSection->id]])
            ->assertStatus(422);

        $this->assertSame('finalized', $schedule->refresh()->status);
    }

    private function fixture(): array
    {
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'CIT',
        ]);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $room = Rooms::create([
            'room_code' => 'CIT 101',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $course = Course::create([
            'course_code' => 'IT 101',
            'course_name' => 'Programming',
            'lecture_hours' => 1,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        $firstSection = Sections::create([
            'section_name' => 'BSIT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $secondSection = Sections::create([
            'section_name' => 'BSIT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        return [$department, $term, $room, $course, $firstSection, $secondSection];
    }

    private function schedule(Departments $department, Terms $term, Rooms $room, Course $course, Sections $section, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'completed',
        ], $overrides));
    }
}
