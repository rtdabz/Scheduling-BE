<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleBatchDepartmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_update_uses_persisted_schedule_department_for_authorization(): void
    {
        [$deptA, $deptB, $term, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB] = $this->fixture();
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]);
        $foreignSchedule = $this->schedule($deptB, $term, $roomB, $courseB, $sectionB, ['status' => 'draft']);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'id' => $foreignSchedule->id,
                'term_id' => $term->id,
                'section_id' => $sectionA->id,
                'course_id' => $courseA->id,
                'room_id' => $roomA->id,
                'department_id' => $deptA->id,
                'day' => 'Tuesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'completed',
            ]],
        ]);

        $response->assertForbidden();
        $foreignSchedule->refresh();
        $this->assertSame($deptB->id, $foreignSchedule->department_id);
        $this->assertSame('Monday', $foreignSchedule->day);
        $this->assertSame('08:00', $foreignSchedule->start_time);
        $this->assertSame('draft', $foreignSchedule->status);
    }

    public function test_batch_delete_ids_use_persisted_schedule_department_for_authorization(): void
    {
        [$deptA, $deptB, $term, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB] = $this->fixture();
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]);
        $localSchedule = $this->schedule($deptA, $term, $roomA, $courseA, $sectionA, ['status' => 'draft']);
        $foreignSchedule = $this->schedule($deptB, $term, $roomB, $courseB, $sectionB);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'id' => $localSchedule->id,
                'term_id' => $term->id,
                'section_id' => $sectionA->id,
                'course_id' => $courseA->id,
                'room_id' => $roomA->id,
                'department_id' => $deptA->id,
                'day' => 'Tuesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'completed',
            ]],
            'delete_ids' => [$foreignSchedule->id],
        ]);

        $response->assertForbidden();
        $this->assertSame('draft', $localSchedule->refresh()->status);
        $this->assertDatabaseHas('schedules', [
            'id' => $foreignSchedule->id,
            'department_id' => $deptB->id,
            'status' => 'draft',
        ]);
    }

    public function test_split_validation_delete_ids_use_persisted_schedule_department_for_authorization(): void
    {
        [$deptA, $deptB, $term, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB] = $this->fixture();
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]);
        $foreignSchedule = $this->schedule($deptB, $term, $roomB, $courseB, $sectionB);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch/validate-splits', [
            'operations' => [[
                'term_id' => $term->id,
                'section_id' => $sectionA->id,
                'course_id' => $courseA->id,
                'room_id' => $roomA->id,
                'department_id' => $deptA->id,
                'day' => 'Wednesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'split_group_id' => 'split-auth-1',
                'meeting_type' => 'lecture',
                'meeting_index' => 1,
            ]],
            'delete_ids' => [$foreignSchedule->id],
        ]);

        $response->assertForbidden();
        $foreignSchedule->refresh();
        $this->assertSame($deptB->id, $foreignSchedule->department_id);
        $this->assertSame('draft', $foreignSchedule->status);
    }

    public function test_batch_status_uses_persisted_schedule_department_for_authorization(): void
    {
        [$deptA, $deptB, $term, , $roomB, , $courseB, , $sectionB] = $this->fixture();
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]);
        $foreignSchedule = $this->schedule($deptB, $term, $roomB, $courseB, $sectionB, ['status' => 'draft']);

        $response = $this->actingAs($user)->patchJson('/api/schedules/batch-status', [
            'ids' => [$foreignSchedule->id],
            'status' => 'submitted',
        ]);

        $response->assertForbidden();
        $foreignSchedule->refresh();
        $this->assertSame('draft', $foreignSchedule->status);
    }

    public function test_vpaa_can_batch_mutate_schedules_across_departments(): void
    {
        [, $deptB, $term, , $roomB, , $courseB, , $sectionB] = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $foreignSchedule = $this->schedule($deptB, $term, $roomB, $courseB, $sectionB, ['status' => 'draft']);

        $response = $this->actingAs($vpaa)->patchJson('/api/schedules/batch-status', [
            'ids' => [$foreignSchedule->id],
            'status' => 'submitted',
        ]);

        $response->assertOk();
        $this->assertSame('submitted', $foreignSchedule->refresh()->status);
    }

    private function fixture(): array
    {
        $deptA = Departments::create(['department_name' => 'Department A', 'department_code' => 'DEPA']);
        $deptB = Departments::create(['department_name' => 'Department B', 'department_code' => 'DEPB']);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $roomA = Rooms::create(['room_code' => 'A101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $deptA->id]);
        $roomB = Rooms::create(['room_code' => 'B101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $deptB->id]);

        $courseA = Course::create([
            'course_code' => 'A101',
            'course_name' => 'Department A Course',
            'lecture_hours' => 1,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $deptA->id,
            'status' => 'active',
        ]);
        $courseB = Course::create([
            'course_code' => 'B101',
            'course_name' => 'Department B Course',
            'lecture_hours' => 1,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $deptB->id,
            'status' => 'active',
        ]);

        $sectionA = Sections::create([
            'section_name' => 'A1',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $deptA->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $sectionB = Sections::create([
            'section_name' => 'B1',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $deptB->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        return [$deptA, $deptB, $term, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB];
    }

    private function schedule(
        Departments $department,
        Terms $term,
        Rooms $room,
        Course $course,
        Sections $section,
        array $overrides = [],
    ): Schedule {
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
            'status' => 'draft',
        ], $overrides));
    }
}
