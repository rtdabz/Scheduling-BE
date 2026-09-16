<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleBatchDepartmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_status_update_persists_action_history_version_and_item(): void
    {
        [$deptA, , $semester, $roomA, , $courseA, , $sectionA] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $schedule = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA, ['status' => 'draft']);

        $this->actingAs($user)->patchJson('/api/schedules/batch-status', [
            'ids' => [$schedule->id],
            'status' => 'completed',
        ])->assertOk();

        $version = ScheduleHistoryVersion::query()->where('action', 'schedule_batch_status_updated')->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertDatabaseHas('schedule_history_items', [
            'history_version_id' => $version->id,
            'original_schedule_id' => $schedule->id,
        ]);
        $this->assertDatabaseHas('scheduling_audit_logs', [
            'action' => 'schedule_batch_status_updated',
            'history_version_id' => $version->id,
        ]);
    }

    public function test_batch_update_uses_persisted_schedule_department_for_authorization(): void
    {
        [$deptA, $deptB, $semester, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $foreignSchedule = $this->schedule($deptB, $semester, $roomB, $courseB, $sectionB, ['status' => 'draft']);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'id' => $foreignSchedule->id,
                'semester_id' => $semester->id,
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
        [$deptA, $deptB, $semester, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $localSchedule = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA, ['status' => 'draft']);
        $foreignSchedule = $this->schedule($deptB, $semester, $roomB, $courseB, $sectionB);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'id' => $localSchedule->id,
                'semester_id' => $semester->id,
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

    public function test_batch_can_delete_schedules_without_operations(): void
    {
        [$deptA, , $semester, $roomA, , $courseA, , $sectionA] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $schedule = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'delete_ids' => [$schedule->id],
        ]);

        $response->assertOk()
            ->assertJson([
                'deleted_schedule_ids' => [$schedule->id],
            ]);

        $this->assertDatabaseMissing('schedules', [
            'id' => $schedule->id,
        ]);
    }

    public function test_batch_can_replace_editable_schedules_for_selected_sections(): void
    {
        [$deptA, , $semester, $roomA, , $courseA, , $sectionA] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $oldDraft = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA, ['status' => 'draft']);
        $oldRevision = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA, [
            'day' => 'Tuesday',
            'status' => 'revision',
        ]);
        $finalized = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA, [
            'day' => 'Wednesday',
            'status' => 'finalized',
        ]);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'semester_id' => $semester->id,
                'section_id' => $sectionA->id,
                'course_id' => $courseA->id,
                'room_id' => $roomA->id,
                'department_id' => $deptA->id,
                'day' => 'Thursday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]],
            'replace_section_ids' => [$sectionA->id],
            'replace_semester_id' => $semester->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('schedules', ['id' => $oldDraft->id]);
        $this->assertDatabaseMissing('schedules', ['id' => $oldRevision->id]);
        // Cleared drafts are removed outright, so nothing lands in the Archive page.
        $this->assertSame(0, Schedule::onlyTrashed()->count());
        $this->assertDatabaseHas('schedules', ['id' => $finalized->id]);
        $this->assertDatabaseHas('schedules', [
            'section_id' => $sectionA->id,
            'course_id' => $courseA->id,
            'day' => 'Thursday',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'draft',
        ]);
    }

    public function test_batch_replace_section_ids_use_section_department_for_authorization(): void
    {
        [$deptA, $deptB, $semester, $roomA, , $courseA, , $sectionA, $sectionB] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'semester_id' => $semester->id,
                'section_id' => $sectionA->id,
                'course_id' => $courseA->id,
                'room_id' => $roomA->id,
                'department_id' => $deptA->id,
                'day' => 'Thursday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]],
            'replace_section_ids' => [$sectionB->id],
            'replace_semester_id' => $semester->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($deptB->id, $sectionB->department_id);
    }

    public function test_batch_can_update_existing_schedule_with_partial_operation(): void
    {
        [$deptA, , $semester, $roomA, , $courseA, , $sectionA] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $schedule = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA, [
            'status' => 'draft',
        ]);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'id' => $schedule->id,
                'status' => 'completed',
            ]],
        ]);

        $response->assertOk();
        $schedule->refresh();
        $this->assertSame('completed', $schedule->status);
        $this->assertSame($semester->id, $schedule->semester_id);
        $this->assertSame($sectionA->id, $schedule->section_id);
        $this->assertSame('Monday', $schedule->day);
    }

    public function test_batch_normalizes_online_room_assignment_to_online_mode(): void
    {
        [$deptA, , $semester, , , $courseA, , $sectionA] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $onlineRoom = Rooms::create([
            'room_code' => 'ONLINE',
            'room_type' => 'online',
            'status' => 'available',
            'department_id' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'semester_id' => $semester->id,
                'section_id' => $sectionA->id,
                'course_id' => $courseA->id,
                'room_id' => $onlineRoom->id,
                'department_id' => $deptA->id,
                'day' => 'Tuesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]],
        ]);

        $response->assertOk();
        $schedule = Schedule::query()->where('course_id', $courseA->id)->firstOrFail();

        $this->assertSame('online', $schedule->mode);
        $this->assertNull($schedule->room_id);
    }

    public function test_batch_field_capacity_uses_department_limit_instead_of_room_legacy_capacity(): void
    {
        [$deptA, , $semester, , , , , ,] = $this->fixture();
        $deptA->update(['field_slot_limit' => 5]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $fieldRoom = Rooms::create([
            'room_code' => 'FIELD',
            'room_name' => 'Shared Field',
            'room_type' => 'field',
            'status' => 'available',
            'department_id' => null,
            'max_concurrent_classes' => 3,
        ]);
        $course = Course::create([
            'course_code' => 'PATHFIT',
            'course_name' => 'Physical Activity',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'minor',
            'room_type_required' => 'field',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $deptA->id,
            'status' => 'active',
        ]);
        $programId = Program::query()->where('department_id', $deptA->id)->value('id');
        $sections = collect(range(1, 4))->map(fn (int $number) => Sections::create([
            'section_name' => "A{$number}",
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $deptA->id,
            'program_id' => $programId,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]));

        $operations = $sections->map(fn (Sections $section) => [
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $fieldRoom->id,
            'department_id' => $deptA->id,
            'day' => 'Monday',
            // The course's full two hours: four sections at once is the point.
            'start_time' => '07:00',
            'end_time' => '09:00',
            'mode' => 'field',
            'status' => 'draft',
        ])->all();

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => $operations,
        ]);

        $response->assertOk();
        $this->assertSame(4, Schedule::query()->where('semester_id', $semester->id)->count());
    }

    public function test_split_validation_delete_ids_use_persisted_schedule_department_for_authorization(): void
    {
        [$deptA, $deptB, $semester, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $foreignSchedule = $this->schedule($deptB, $semester, $roomB, $courseB, $sectionB);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch/validate-splits', [
            'operations' => [[
                'semester_id' => $semester->id,
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
        [$deptA, $deptB, $semester, , $roomB, , $courseB, , $sectionB] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $foreignSchedule = $this->schedule($deptB, $semester, $roomB, $courseB, $sectionB, ['status' => 'draft']);

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
        [, $deptB, $semester, , $roomB, , $courseB, , $sectionB] = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $foreignSchedule = $this->schedule($deptB, $semester, $roomB, $courseB, $sectionB, ['status' => 'draft']);

        $response = $this->actingAs($vpaa)->patchJson('/api/schedules/batch-status', [
            'ids' => [$foreignSchedule->id],
            'status' => 'submitted',
        ]);

        $response->assertOk();
        $this->assertSame('submitted', $foreignSchedule->refresh()->status);
    }

    public function test_batch_rejects_unknown_references_with_field_keyed_errors(): void
    {
        [$deptA, , $semester, $roomA, , $courseA, , $sectionA] = $this->fixture();
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));

        $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'semester_id' => $semester->id,
                'section_id' => $sectionA->id,
                'course_id' => $courseA->id,
                'room_id' => $roomA->id + 999,
                'department_id' => $deptA->id,
                'day' => 'Tuesday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]],
            'delete_ids' => [987654],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operations.0.room_id', 'delete_ids.0'])
            ->assertJsonMissingValidationErrors(['operations.0.section_id', 'operations.0.course_id']);

        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_schedule_listings_are_scoped_to_the_requesting_department(): void
    {
        [$deptA, $deptB, $semester, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB] = $this->fixture();
        $secretaryA = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $deptA->id]));
        $own = $this->schedule($deptA, $semester, $roomA, $courseA, $sectionA);
        $foreign = $this->schedule($deptB, $semester, $roomB, $courseB, $sectionB);
        // Department B owns this meeting but delegated its teaching to A.
        $delegatedCourse = Course::create([
            'course_code' => 'GEC101', 'course_name' => 'Delegated Course', 'lecture_hours' => 1, 'lab_hours' => 0,
            'units' => 1, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1',
            'semester' => '1st', 'department_id' => $deptB->id, 'teaching_department_id' => $deptA->id, 'status' => 'active',
        ]);
        $delegated = $this->schedule($deptB, $semester, $roomB, $delegatedCourse, $sectionB, ['start_time' => '10:00', 'end_time' => '11:00']);

        $ids = fn ($response): array => collect($response->assertOk()->json())->pluck('id')->sort()->values()->all();

        $this->assertSame(
            [$own->id, $delegated->id],
            $ids($this->actingAs($secretaryA)->getJson("/api/schedules/semester/{$semester->id}")),
        );
        $this->assertSame(
            [$delegated->id],
            $ids($this->actingAs($secretaryA)->getJson("/api/schedules/section/{$sectionB->id}")),
        );
        $semesterRows = $this->actingAs($secretaryA)->getJson("/api/schedules/semester/{$semester->id}")->json();
        $this->assertArrayNotHasKey('logo', $semesterRows[0]['department']);
        $this->assertNotContains($foreign->id, array_column($semesterRows, 'id'));

        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        $this->assertCount(3, $this->actingAs($vpaa)->getJson("/api/schedules/semester/{$semester->id}")->assertOk()->json());
    }

    private function fixture(): array
    {
        $deptA = Departments::create(['department_name' => 'Department A', 'department_code' => 'DEPA']);
        $deptB = Departments::create(['department_name' => 'Department B', 'department_code' => 'DEPB']);
        // Schedule capabilities and section scheduling both require the owning
        // department to have a program.
        $programA = Program::create(['department_id' => $deptA->id, 'code' => 'PA', 'name' => 'Program A']);
        $programB = Program::create(['department_id' => $deptB->id, 'code' => 'PB', 'name' => 'Program B']);
        $semester = Semester::create([
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
            'program_id' => $programA->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $sectionB = Sections::create([
            'section_name' => 'B1',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $deptB->id,
            'program_id' => $programB->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        return [$deptA, $deptB, $semester, $roomA, $roomB, $courseA, $courseB, $sectionA, $sectionB];
    }

    private function schedule(
        Departments $department,
        Semester $semester,
        Rooms $room,
        Course $course,
        Sections $section,
        array $overrides = [],
    ): Schedule {
        return Schedule::create(array_merge([
            'semester_id' => $semester->id,
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
