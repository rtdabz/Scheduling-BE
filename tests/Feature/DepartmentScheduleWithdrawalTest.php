<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\SchedulingAuditLog;
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
        $history = ScheduleHistory::query()->where('action', 'schedule_withdrawn')->get();
        $this->assertCount(1, $history);
        $this->assertSame($first->id, $history->first()->schedule_id);
        $this->assertSame([$firstSection->id], $history->first()->changes['selected_section_ids']);
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

    public function test_withdrawal_releases_instructors_only_for_the_withdrawn_sections(): void
    {
        [$department, $term, $room, $course, $firstSection, $secondSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $instructor = $this->instructor($department);

        $withdrawn = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);
        $untouched = $this->schedule($department, $term, $room, $course, $secondSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk()
            ->assertJsonPath('instructors_released', 1);

        // The section being revised loses its instructor: the schedule it was
        // made against is about to change, and no UI can reach the assignment
        // while the row sits outside the assignment statuses.
        $this->assertNull($withdrawn->refresh()->faculty_id);
        $this->assertSame('revision', $withdrawn->status);

        // A section that was only pushed back to Done keeps its instructor —
        // nothing about it is being edited.
        $this->assertSame($instructor->id, $untouched->refresh()->faculty_id);
        $this->assertSame('completed', $untouched->status);
    }

    public function test_released_instructors_are_recorded_in_the_audit_log(): void
    {
        [$department, $term, $room, $course, $firstSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $instructor = $this->instructor($department);

        $schedule = $this->schedule($department, $term, $room, $course, $firstSection, [
            'status' => 'faculty_assignment',
            'faculty_id' => $instructor->id,
        ]);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk();

        $log = SchedulingAuditLog::query()
            ->where('action', 'instructor_assignment_released')
            ->where('section_id', $firstSection->id)
            ->firstOrFail();

        $this->assertSame($secretary->id, $log->user_id);
        $this->assertSame($department->id, $log->department_id);
        $this->assertSame('schedule_withdrawn', $log->metadata['reason']);
        $this->assertSame(1, $log->metadata['released_count']);
        $this->assertSame([$schedule->id], $log->metadata['schedule_ids']);
        $this->assertSame(
            $instructor->id,
            $log->metadata['previous_faculty_ids'][(string) $schedule->id],
        );
    }

    public function test_withdrawal_without_instructors_reports_none_released(): void
    {
        [$department, $term, $room, $course, $firstSection] = $this->fixture();
        $secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $this->schedule($department, $term, $room, $course, $firstSection, ['status' => 'submitted']);

        $this->actingAs($secretary)
            ->postJson("/api/departments/{$department->id}/withdraw-submission", [
                'section_ids' => [$firstSection->id],
            ])
            ->assertOk()
            ->assertJsonPath('instructors_released', 0);

        $this->assertDatabaseHas('scheduling_audit_logs', [
            'action' => 'schedule_withdrawn',
            'department_id' => $department->id,
        ]);
        $this->assertDatabaseCount('scheduling_audit_logs', 1);
    }

    private function instructor(Departments $department): Faculty
    {
        return Faculty::create([
            'first_name' => 'Withdraw',
            'last_name' => 'Instructor',
            'employment_type' => 'full-time',
            'max_units' => 18,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
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
