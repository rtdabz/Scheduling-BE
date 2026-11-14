<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetArchiveInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_classifies_on_record_level_evidence_and_changes_nothing(): void
    {
        $department = Departments::create(['department_name' => 'Department A', 'department_code' => 'DEPA']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'PA', 'name' => 'Program A']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $course = Course::create(['course_code' => 'A101', 'course_name' => 'Course', 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $section = Sections::create(['section_name' => 'A1', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']);
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);
        $make = fn (string $day): Schedule => Schedule::create(['semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $course->id, 'department_id' => $department->id, 'day' => $day, 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'online', 'status' => 'rejected']);

        $explicit = $make('Monday');
        $resetConfirmed = $make('Tuesday');
        $batchOnly = $make('Wednesday');
        $noEvent = $make('Thursday');
        foreach ([$explicit, $resetConfirmed, $batchOnly, $noEvent] as $schedule) {
            $schedule->delete();
        }
        $log = static fn (string $action, array $metadata) => SchedulingAuditLog::create(['user_id' => $user->id, 'semester_id' => $semester->id, 'department_id' => $department->id, 'action' => $action, 'metadata' => $metadata, 'created_at' => now()]);
        $log('schedule_deleted', ['schedule_id' => $explicit->id]);
        $log('schedule_batch_deleted', ['schedule_ids' => [$resetConfirmed->id, $batchOnly->id]]);
        DB::table('system_notifications')->insert(['user_id' => $user->id, 'type' => 'cross_department_instructors_released', 'title' => 'Released', 'message' => 'Department A reset its schedule, so your instructor assignment was released.', 'metadata' => json_encode(['schedule_ids' => [$resetConfirmed->id]]), 'created_at' => now(), 'updated_at' => now()]);
        $version = ScheduleHistoryVersion::create(['semester_id' => $semester->id, 'department_id' => $department->id, 'action' => 'schedule_batch_deleted', 'source' => 'batch_delete']);
        ScheduleHistoryItem::create(['history_version_id' => $version->id, 'original_schedule_id' => $resetConfirmed->id, 'before_snapshot' => ['id' => $resetConfirmed->id]]);

        $tables = ['schedules', 'schedule_splits', 'schedule_history_versions', 'schedule_history_items', 'scheduling_audit_logs', 'system_notifications'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all();
        $path = tempnam(sys_get_temp_dir(), 'inventory');

        $this->artisan('schedules:reset-archive-inventory', ['--output' => $path])->assertSuccessful();

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toArray()])->all());
        $report = json_decode(file_get_contents($path), true);
        unlink($path);
        $byId = collect($report['schedules'])->keyBy('id');
        $this->assertSame('intentionally_archived', $byId[$explicit->id]['classification']);
        $this->assertSame('confirmed_reset', $byId[$resetConfirmed->id]['classification']);
        $this->assertSame('uncertain', $byId[$batchOnly->id]['classification']);
        $this->assertSame('uncertain', $byId[$noEvent->id]['classification']);
        $this->assertSame([$resetConfirmed->id], $report['proposed_deletion']['schedule_ids']);
        $this->assertStringContainsString('schedule_history_items reference it', implode(' ', $byId[$resetConfirmed->id]['protected_dependencies']));
    }
}
