<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSplit;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schedules have no archive. Every delete path removes classes and their split rows permanently,
 * Schedule History is never touched, and the Archive API neither lists nor restores schedules.
 */
class ScheduleDeletionIsPermanentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_class_at_any_editable_status_is_permanent_and_leaves_history_alone(): void
    {
        foreach (['draft', 'completed', 'revision', 'rejected', 'rejected_by_dean'] as $index => $status) {
            $f = $this->fixture((string) $index);
            $schedule = $this->schedule($f, ['status' => $status, 'split_group_id' => (string) Str::uuid(), 'meeting_type' => 'lecture', 'meeting_index' => 1]);
            $version = ScheduleHistoryVersion::create(['semester_id' => $f['semester']->id, 'department_id' => $f['department']->id, 'action' => 'schedule_submitted', 'source' => 'department_workflow']);
            ScheduleHistoryItem::create(['history_version_id' => $version->id, 'original_schedule_id' => $schedule->id, 'after_snapshot' => ['id' => $schedule->id]]);
            $history = [DB::table('schedule_history_versions')->orderBy('id')->get()->toArray(), DB::table('schedule_history_items')->orderBy('id')->get()->toArray()];

            $this->actingAs($f['user'])->deleteJson("/api/schedules/{$schedule->id}")
                ->assertOk()->assertJsonPath('archived', false);

            $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
            $this->assertSame(0, ScheduleSplit::withTrashed()->where('schedule_id', $schedule->id)->count(), $status);
            $this->assertEquals($history, [DB::table('schedule_history_versions')->orderBy('id')->get()->toArray(), DB::table('schedule_history_items')->orderBy('id')->get()->toArray()]);
        }
    }

    public function test_group_delete_removes_every_linked_meeting_permanently(): void
    {
        $f = $this->fixture();
        $group = (string) Str::uuid();
        $lecture = $this->schedule($f, ['split_group_id' => $group, 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $lab = $this->schedule($f, ['day' => 'Wednesday', 'split_group_id' => $group, 'meeting_type' => 'laboratory', 'meeting_index' => 2]);

        $this->actingAs($f['user'])->deleteJson("/api/schedules/{$lecture->id}?delete_group=true")->assertOk()->assertJsonPath('archived', false);

        $this->assertDatabaseMissing('schedules', ['id' => $lecture->id]);
        $this->assertDatabaseMissing('schedules', ['id' => $lab->id]);
        $this->assertSame(0, ScheduleSplit::withTrashed()->whereIn('schedule_id', [$lecture->id, $lab->id])->count());
    }

    public function test_batch_and_split_deletion_never_archive(): void
    {
        $f = $this->fixture();
        $returned = $this->schedule($f, ['status' => 'rejected_by_dean']);
        $withSplit = $this->schedule($f, ['day' => 'Friday', 'split_group_id' => (string) Str::uuid(), 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $split = ScheduleSplit::where('schedule_id', $withSplit->id)->sole();

        $this->actingAs($f['user'])->postJson('/api/schedules/batch', ['operations' => [], 'delete_ids' => [$returned->id]])->assertOk();
        $this->actingAs($f['user'])->deleteJson("/api/schedule-splits/{$split->id}")->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $returned->id]);
        $this->assertSame(0, ScheduleSplit::withTrashed()->whereKey($split->id)->count());
    }

    public function test_the_archive_neither_lists_nor_restores_schedules(): void
    {
        $f = $this->fixture();
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);
        // A row archived before schedules lost their archive stays hidden and unrecoverable.
        $legacy = $this->schedule($f, ['split_group_id' => (string) Str::uuid(), 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $legacy->delete();
        $split = ScheduleSplit::onlyTrashed()->where('schedule_id', $legacy->id)->sole();

        $index = $this->actingAs($vpaa)->getJson('/api/archives')->assertOk();
        $this->assertArrayNotHasKey('schedules', $index->json('counts'));
        $this->assertArrayNotHasKey('schedule-splits', $index->json('counts'));
        $this->assertSame(0, $index->json('total_archived'));
        $this->actingAs($vpaa)->getJson('/api/archives?type=schedules')->assertJsonValidationErrors('type');
        $this->actingAs($vpaa)->getJson("/api/archives/schedules/{$legacy->id}")->assertNotFound();
        $this->actingAs($vpaa)->postJson("/api/archives/schedules/{$legacy->id}/restore")->assertNotFound();
        $this->actingAs($vpaa)->postJson("/api/archives/schedule-splits/{$split->id}/restore")->assertNotFound();

        $this->assertSoftDeleted('schedules', ['id' => $legacy->id]);
    }

    /** @return array<string, mixed> */
    private function fixture(string $suffix = ''): array
    {
        $department = Departments::create(['department_name' => 'Department '.$suffix, 'department_code' => 'D'.$suffix.'X']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'P'.$suffix.'X', 'name' => 'Program']);
        $semester = Semester::query()->where('is_active', true)->first() ?? Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);

        return [
            'department' => $department,
            'program' => $program,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'R'.$suffix.'101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create(['course_code' => 'C'.$suffix.'101', 'course_name' => 'Course', 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']),
            'section' => Sections::create(['section_name' => 'A'.$suffix, 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    private function schedule(array $f, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $f['semester']->id, 'section_id' => $f['section']->id, 'course_id' => $f['course']->id,
            'room_id' => $f['room']->id, 'department_id' => $f['department']->id, 'program_id' => $f['program']->id,
            'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => 'draft',
        ], $overrides));
    }
}
