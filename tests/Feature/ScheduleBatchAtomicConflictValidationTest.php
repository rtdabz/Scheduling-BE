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

/**
 * Guards the fix for audit finding #1: conflict validation runs inside the same
 * transaction as the write, so a rejected batch leaves no partial state behind.
 */
class ScheduleBatchAtomicConflictValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conflicting_batch_is_rejected_and_creates_nothing(): void
    {
        [$dept, $term, $room, $course, $otherCourse, $section, $user] = $this->fixture();

        $this->schedule($dept, $term, $room, $course, $section, [
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [
                [
                    'term_id' => $term->id,
                    'section_id' => $section->id,
                    'course_id' => $otherCourse->id,
                    'room_id' => $room->id,
                    'department_id' => $dept->id,
                    'day' => 'Wednesday',
                    'start_time' => '13:00',
                    'end_time' => '14:00',
                    'mode' => 'on-site',
                    'status' => 'draft',
                ],
                [
                    'term_id' => $term->id,
                    'section_id' => $section->id,
                    'course_id' => $otherCourse->id,
                    'room_id' => $room->id,
                    'department_id' => $dept->id,
                    'day' => 'Monday',
                    'start_time' => '08:00',
                    'end_time' => '09:00',
                    'mode' => 'on-site',
                    'status' => 'draft',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'violations']);

        // The non-conflicting first operation must not have been persisted.
        $this->assertDatabaseMissing('schedules', [
            'section_id' => $section->id,
            'day' => 'Wednesday',
        ]);
        $this->assertSame(1, Schedule::query()->count());
    }

    public function test_rejected_batch_does_not_apply_its_delete_ids(): void
    {
        [$dept, $term, $room, $course, $otherCourse, $section, $user] = $this->fixture();

        $existing = $this->schedule($dept, $term, $room, $course, $section, [
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);
        $unrelated = $this->schedule($dept, $term, $room, $otherCourse, $section, [
            'day' => 'Friday',
            'start_time' => '15:00',
            'end_time' => '16:00',
        ]);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'term_id' => $term->id,
                'section_id' => $section->id,
                'course_id' => $otherCourse->id,
                'room_id' => $room->id,
                'department_id' => $dept->id,
                'day' => 'Monday',
                'start_time' => '08:00',
                'end_time' => '09:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]],
            'delete_ids' => [$unrelated->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('schedules', ['id' => $existing->id]);
        $this->assertDatabaseHas('schedules', ['id' => $unrelated->id]);
    }

    public function test_valid_batch_still_saves_and_deletes(): void
    {
        [$dept, $term, $room, $course, $otherCourse, $section, $user] = $this->fixture();

        $stale = $this->schedule($dept, $term, $room, $course, $section, [
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);

        $response = $this->actingAs($user)->postJson('/api/schedules/batch', [
            'operations' => [[
                'term_id' => $term->id,
                'section_id' => $section->id,
                'course_id' => $otherCourse->id,
                'room_id' => $room->id,
                'department_id' => $dept->id,
                'day' => 'Thursday',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'mode' => 'on-site',
                'status' => 'draft',
            ]],
            'delete_ids' => [$stale->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('deleted_schedule_ids.0', $stale->id);
        $this->assertSoftDeleted('schedules', ['id' => $stale->id]);
        $this->assertDatabaseHas('schedules', [
            'section_id' => $section->id,
            'course_id' => $otherCourse->id,
            'day' => 'Thursday',
        ]);
    }

    public function test_conflicting_single_update_is_rejected_and_leaves_row_untouched(): void
    {
        [$dept, $term, $room, $course, $otherCourse, $section, $user] = $this->fixture();

        $blocking = $this->schedule($dept, $term, $room, $course, $section, [
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);
        $target = $this->schedule($dept, $term, $room, $otherCourse, $section, [
            'day' => 'Friday',
            'start_time' => '15:00',
            'end_time' => '16:00',
        ]);

        $response = $this->actingAs($user)->putJson("/api/schedules/{$target->id}", [
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'violations']);

        $target->refresh();
        $this->assertSame('Friday', $target->day);
        $this->assertSame('15:00', $target->start_time);
        $this->assertDatabaseHas('schedules', ['id' => $blocking->id, 'day' => 'Monday']);
    }

    public function test_valid_single_update_still_persists(): void
    {
        [$dept, $term, $room, $course, , $section, $user] = $this->fixture();

        $target = $this->schedule($dept, $term, $room, $course, $section, [
            'day' => 'Friday',
            'start_time' => '15:00',
            'end_time' => '16:00',
        ]);

        $response = $this->actingAs($user)->putJson("/api/schedules/{$target->id}", [
            'day' => 'Thursday',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        $response->assertOk();
        $target->refresh();
        $this->assertSame('Thursday', $target->day);
        $this->assertSame('10:00', $target->start_time);
    }

    /** @return array{0: Departments, 1: Terms, 2: Rooms, 3: Course, 4: Course, 5: Sections, 6: User} */
    private function fixture(): array
    {
        $dept = Departments::create(['department_name' => 'Atomic Dept', 'department_code' => 'ATM']);
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $room = Rooms::create([
            'room_code' => 'ATM101',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $dept->id,
        ]);

        $course = $this->course('ATM101', 'Atomic Course One', $dept);
        $otherCourse = $this->course('ATM102', 'Atomic Course Two', $dept);

        $section = Sections::create([
            'section_name' => 'ATM-1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $dept->id]);

        return [$dept, $term, $room, $course, $otherCourse, $section, $user];
    }

    private function course(string $code, string $name, Departments $department): Course
    {
        return Course::create([
            'course_code' => $code,
            'course_name' => $name,
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
