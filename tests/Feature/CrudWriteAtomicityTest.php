<?php

namespace Tests\Feature;

use App\Exceptions\ScheduleConflictException;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Lock\SchedulingScopeLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CrudWriteAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $department = Departments::create(['department_name' => 'Computing', 'department_code' => 'CC']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $curriculum = Curriculum::create(['name' => 'Original', 'code' => 'IT26', 'department_id' => $department->id,
            'program_id' => $program->id, 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $course = Course::create(['course_code' => 'IT101', 'course_name' => 'Computing', 'department_id' => $department->id,
            'program_id' => $program->id, 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major',
            'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $section = Sections::create(['section_name' => 'IT1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id,
            'curriculum_id' => $curriculum->id, 'status' => 'active']);
        $faculty = Faculty::create(['first_name' => 'Cache', 'last_name' => 'Teacher', 'department_id' => $department->id,
            'program_id' => $program->id, 'employment_type' => 'part-time', 'max_units' => 21, 'status' => 'active']);
        $room = Rooms::create(['room_code' => 'IT101', 'room_type' => 'lecture', 'department_id' => $department->id, 'status' => 'available']);
        $secretary = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]),
            [...self::SCHEDULE_WORKSPACE_CAPABILITIES, 'curriculum.manage']);
        $this->f = compact('department', 'program', 'semester', 'curriculum', 'course', 'section', 'faculty', 'room', 'secretary');
        $this->actingAs($secretary);
    }

    private function payload(): array
    {
        return ['semester_id' => $this->f['semester']->id, 'section_id' => $this->f['section']->id,
            'course_id' => $this->f['course']->id, 'room_id' => $this->f['room']->id,
            'department_id' => $this->f['department']->id, 'program_id' => $this->f['program']->id,
            'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '11:00', 'mode' => 'on-site'];
    }

    public function test_single_create_validates_state_after_entering_the_shared_lock(): void
    {
        $payload = $this->payload();
        $entered = false;
        $this->mock(SchedulingScopeLock::class)->shouldReceive('execute')->once()
            ->with([$this->f['semester']->id], \Mockery::type('callable'))
            ->andReturnUsing(function ($ids, $callback) use ($payload, &$entered) {
                $entered = true;
                Schedule::create([...$payload, 'status' => 'draft', 'curriculum_id' => $this->f['curriculum']->id]);

                return $callback();
            });
        $this->postJson('/api/schedules', $payload)->assertUnprocessable();
        $this->assertTrue($entered);
        $this->assertSame(1, Schedule::count());
    }

    public function test_lock_contention_returns_a_retryable_validation_response_without_writing(): void
    {
        $this->mock(SchedulingScopeLock::class)->shouldReceive('execute')->once()
            ->andThrow(new ScheduleConflictException([
                ['rule' => 'concurrent_write', 'message' => 'Please retry.'],
            ]));
        $this->postJson('/api/schedules', $this->payload())->assertUnprocessable()
            ->assertJsonPath('violations.0.rule', 'concurrent_write');
        $this->assertSame(0, Schedule::count());
    }

    public function test_single_create_rolls_back_if_required_audit_fails(): void
    {
        $histories = DB::table('schedule_history_versions')->count();
        Event::listen('eloquent.creating: '.SchedulingAuditLog::class, function () {
            throw new \RuntimeException('Injected audit failure');
        });
        $this->postJson('/api/schedules', $this->payload())->assertStatus(500);
        $this->assertSame(0, Schedule::count());
        $this->assertSame($histories, DB::table('schedule_history_versions')->count());
    }

    public function test_group_delete_rolls_back_all_members_and_splits_on_failure(): void
    {
        $first = Schedule::create([...$this->payload(), 'status' => 'draft', 'split_group_id' => 'atomic-group', 'meeting_type' => 'lecture', 'meeting_index' => 1]);
        $second = Schedule::create([...$this->payload(), 'day' => 'Tuesday', 'status' => 'draft', 'split_group_id' => 'atomic-group', 'meeting_type' => 'lecture', 'meeting_index' => 2]);
        $histories = DB::table('schedule_history_versions')->count();
        Event::listen('eloquent.deleting: '.Schedule::class, function (Schedule $schedule) use ($second) {
            if ($schedule->id === $second->id) {
                throw new \RuntimeException('Injected second deletion failure');
            }
        });
        $this->deleteJson('/api/schedules/'.$first->id.'?delete_group=true')->assertStatus(500);
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
        $this->assertSame(2, ScheduleSplit::count());
        $this->assertSame($histories, DB::table('schedule_history_versions')->count());
        $this->assertDatabaseMissing('scheduling_audit_logs', ['action' => 'schedule_deleted']);
    }

    public function test_group_delete_rechecks_stage_inside_the_lock(): void
    {
        $schedule = Schedule::create([...$this->payload(), 'status' => 'draft']);
        $this->mock(SchedulingScopeLock::class)->shouldReceive('execute')->once()->andReturnUsing(function ($ids, $callback) use ($schedule) {
            $schedule->update(['status' => 'finalized']);

            return $callback();
        });
        $this->deleteJson('/api/schedules/'.$schedule->id)->assertUnprocessable();
        $this->assertNotSoftDeleted($schedule);
    }

    public function test_curriculum_duplicate_rolls_back_parent_when_pivot_insert_fails(): void
    {
        $curricula = Curriculum::count();
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into "curriculum_course"')) {
                throw new \RuntimeException('Injected pivot failure');
            }
        });
        $this->postJson('/api/curriculum/'.$this->f['curriculum']->id.'/duplicate')->assertStatus(500);
        $this->assertSame($curricula, Curriculum::count());
        $this->assertSame(1, DB::table('curriculum_course')->count());
    }
}
