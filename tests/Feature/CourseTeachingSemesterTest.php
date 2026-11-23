<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseTeachingAssignment;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CourseTeachingSemesterTest extends TestCase
{
    use RefreshDatabase;

    private Semester $first;

    private Semester $second;

    private Departments $source;

    private Departments $target;

    private Program $sourceProgram;

    private Program $targetProgram;

    private Course $course;

    private Rooms $room;

    private User $sender;

    private User $receiver;

    private User $vpaa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->first = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $this->second = Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => false, 'is_enabled' => true]);
        $this->source = Departments::create(['department_name' => 'Business', 'department_code' => 'CBA']);
        $this->target = Departments::create(['department_name' => 'National Service Training', 'department_code' => 'NSTP']);
        $this->sourceProgram = Program::create(['department_id' => $this->source->id, 'code' => 'BSBA', 'name' => 'Business Administration']);
        $this->targetProgram = Program::create(['department_id' => $this->target->id, 'code' => 'CWTS', 'name' => 'Civic Welfare Training']);
        $this->course = Course::create(['course_code' => 'CWTS 101', 'course_name' => 'Civic Welfare Training',
            'department_id' => $this->source->id, 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
        foreach ([1, 2] as $period) {
            $curriculum = Curriculum::create(['name' => 'BSBA '.$period, 'code' => 'BSBA-'.$period,
                'department_id' => $this->source->id, 'program_id' => $this->sourceProgram->id,
                'effective_school_year' => '2026-2027', 'status' => 'active']);
            DB::table('curriculum_course')->insert(['curriculum_id' => $curriculum->id, 'course_id' => $this->course->id,
                'year_level' => 1, 'semester' => $period]);
        }
        $this->room = Rooms::create(['room_code' => 'BA101', 'room_type' => 'lecture', 'department_id' => $this->source->id, 'status' => 'available']);
        $this->sender = User::factory()->create(['role' => 'program_head', 'department_id' => $this->source->id, 'program_id' => $this->sourceProgram->id]);
        $this->receiver = User::factory()->create(['role' => 'secretary', 'department_id' => $this->target->id]);
        $this->vpaa = User::factory()->create(['role' => 'vpaa']);
    }

    public function test_a_new_semester_starts_unassigned_and_old_rows_and_json_keep_their_own_delegation(): void
    {
        $old = $this->meeting($this->first);
        $this->assign($this->first);
        $before = $old->fresh()->getAttributes();
        // Warm both read caches before switching.
        $this->actingAs($this->receiver)->getJson('/api/instructor-assignments')->assertOk()->assertJsonCount(1, 'schedules');
        $this->actingAs($this->sender)->getJson('/api/course-teaching-assignments')->assertOk();
        $this->activate($this->second);
        $this->assertNull($this->course->fresh()->teaching_department_id);
        $this->assertNull($this->course->fresh()->teaching_program_id);
        $this->assertNull($this->course->fresh()->teaching_source_program_id);
        $this->assertDatabaseMissing('course_teaching_assignments', ['course_id' => $this->course->id, 'semester_id' => $this->second->id]);
        $this->actingAs($this->sender)->getJson('/api/course-teaching-assignments')->assertOk()
            ->assertJsonPath('active_semester.id', $this->second->id)->assertJsonPath('courses.0.teaching_department_id', null);
        $this->actingAs($this->receiver)->getJson('/api/instructor-assignments')->assertOk()->assertJsonCount(0, 'schedules');
        $this->getJson('/api/course-teaching-assignments')->assertOk()->assertJsonCount(0, 'incoming_cross_department_courses');
        $new = $this->meeting($this->second);
        $this->assertEquals($before, $old->fresh()->getAttributes());
        $this->assertEqualsCanonicalizing([$old->id], Schedule::delegatedTo($this->target->id)->pluck('id')->all());
        $this->assertSame($this->target->id, SchedulingPolicy::scheduleTeachingDepartmentId($old->fresh()));
        $this->assertSame($this->source->id, SchedulingPolicy::scheduleTeachingDepartmentId($new->fresh()));
        // Exercise serialization before reading the relation, and the same course in two semesters.
        $rows = Schedule::with('course')->whereIn('id', [$old->id, $new->id])->orderBy('id')->get()->toArray();
        $this->assertSame($this->target->id, $rows[0]['course']['teaching_department_id']);
        $this->assertNull($rows[1]['course']['teaching_department_id']);
        $this->assertArrayNotHasKey('teaching_assignments', $rows[0]['course']);
        $snapshot = app(SchedulingSnapshotRepository::class)->capture($this->first->id, $this->source->id, courseIds: [$this->course->id]);
        $this->assertSame($this->target->id, $snapshot->coursesById[$this->course->id]['teaching_department_id']);
    }

    public function test_the_same_target_in_a_new_semester_is_a_new_assignment_and_reactivation_restores_saved_assignments(): void
    {
        $this->assign($this->first);
        $this->activate($this->second);
        $this->assign($this->second);
        $this->assign($this->second); // A retry within one semester stays quiet.
        $notices = SystemNotification::where('user_id', $this->receiver->id)->where('type', 'incoming_cross_department_courses')->orderBy('id')->get();
        $this->assertSame([$this->first->id, $this->second->id], $notices->pluck('semester_id')->all());
        $this->actingAs($this->sender)->postJson('/api/course-teaching-assignments/batch-remove', [
            'course_ids' => [$this->course->id], 'expected_active_semester_id' => $this->second->id,
        ])->assertOk();
        $this->assertNull($this->course->fresh()->teaching_department_id);
        $this->assertDatabaseHas('course_teaching_assignments', ['course_id' => $this->course->id, 'semester_id' => $this->first->id,
            'teaching_department_id' => $this->target->id, 'teaching_program_id' => $this->targetProgram->id,
            'teaching_source_program_id' => $this->sourceProgram->id]);
        $this->activate($this->first);
        $this->assertSame($this->target->id, $this->course->fresh()->teaching_department_id);
        $this->assertSame($this->targetProgram->id, $this->course->fresh()->teaching_program_id);
        $this->assertSame($this->sourceProgram->id, $this->course->fresh()->teaching_source_program_id);
        // A duplicate activation is a retry, not a semester change, and must preserve this save.
        $this->activate($this->first);
        $this->assertSame($this->target->id, $this->course->fresh()->teaching_department_id);
        $this->assertSame(2, SystemNotification::where('user_id', $this->receiver->id)->where('type', 'incoming_cross_department_courses')->count());
        // An explicit removal is saved too; returning must not resurrect the old handoff.
        $this->activate($this->second);
        $this->assertNull($this->course->fresh()->teaching_department_id);
        $this->assertNull($this->course->fresh()->teaching_program_id);
        $this->assertNull($this->course->fresh()->teaching_source_program_id);
        $this->actingAs($this->receiver)->getJson('/api/course-teaching-assignments')->assertOk()
            ->assertJsonCount(0, 'incoming_cross_department_courses');
    }

    public function test_the_same_period_in_another_academic_year_is_independent_and_section_overrides_stay_with_their_class(): void
    {
        $this->assign($this->first);
        $returned = $this->meeting($this->first);
        $returned->update(['delegation_returned_from_department_id' => $this->target->id,
            'section_teaching_department_id' => $this->source->id, 'section_teaching_program_id' => $this->sourceProgram->id]);
        $nextYear = Semester::create(['academic_year' => '2027-2028', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        $this->activate($nextYear);
        $this->assertNull($this->course->fresh()->teaching_department_id);
        $this->assertSame($this->source->id, SchedulingPolicy::scheduleTeachingDepartmentId($returned->fresh()));
        $this->assertDatabaseMissing('course_teaching_assignments', ['semester_id' => $nextYear->id]);
        $this->activate($this->first);
        $this->assertSame($this->target->id, $this->course->fresh()->teaching_department_id);
        $this->assertSame($this->target->id, $returned->fresh()->delegation_returned_from_department_id);
        $this->assertSame($this->source->id, $returned->fresh()->section_teaching_department_id);
        $this->assertSame($this->sourceProgram->id, $returned->fresh()->section_teaching_program_id);
        $this->assertFalse(Schedule::whereKey($returned->id)->delegatedTo($this->target->id)->exists());
        $this->assertSame($this->source->id, SchedulingPolicy::scheduleTeachingDepartmentId($returned->fresh()));
    }

    public function test_missing_active_semester_and_stale_clients_cannot_write_delegations(): void
    {
        $this->activate($this->second);
        $this->actingAs($this->sender)->postJson('/api/course-teaching-assignments/batch', $this->payload($this->first))->assertConflict();
        $this->assertNull($this->course->fresh()->teaching_department_id);
        $this->assertSame(0, CourseTeachingAssignment::count());
        Semester::query()->update(['is_active' => false]);
        $this->postJson('/api/course-teaching-assignments/batch', $this->payload($this->second))->assertUnprocessable();
        $this->assertSame(0, CourseTeachingAssignment::count());
    }

    public function test_first_second_summer_and_new_year_start_independently_and_preserve_saved_work_on_return(): void
    {
        $summer = Semester::create(['academic_year' => '2026-2027', 'semester' => 'summer', 'is_active' => false, 'is_enabled' => true]);
        $nextYear = Semester::create(['academic_year' => '2027-2028', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        $periods = [$this->first, $this->second, $summer, $nextYear];
        $rows = [];
        $savedAttributes = [];
        foreach ($periods as $period) {
            $this->activate($period);
            foreach (['teaching_department_id', 'teaching_program_id', 'teaching_source_program_id'] as $field) {
                $this->assertNull($this->course->fresh()->getAttribute($field));
            }
            $row = $this->meeting($period);
            $this->assertNull($row->section_teaching_department_id);
            $this->assertNull($row->section_teaching_program_id);
            $this->assertNull($row->delegation_returned_from_department_id);
            $this->course->fresh()->update(['teaching_department_id' => $this->target->id,
                'teaching_program_id' => $this->targetProgram->id, 'teaching_source_program_id' => $this->sourceProgram->id]);
            $row->update(['delegation_returned_from_department_id' => $this->target->id,
                'delegation_returned_at' => now(), 'section_teaching_department_id' => $this->source->id,
                'section_teaching_program_id' => $this->sourceProgram->id, 'section_teaching_assigned_at' => now(),
                'faculty_assignment_done' => true]);
            $rows[$period->id] = $row;
            $savedAttributes[$period->id] = $row->fresh()->getAttributes();
        }
        $savedMappings = CourseTeachingAssignment::orderBy('id')->get()->toArray();
        foreach ($periods as $period) {
            $this->activate($period);
            $this->assertSame($this->target->id, $this->course->fresh()->teaching_department_id);
            $this->assertSame($this->targetProgram->id, $this->course->fresh()->teaching_program_id);
            $this->assertSame($this->sourceProgram->id, $this->course->fresh()->teaching_source_program_id);
            $this->assertSame($this->source->id, SchedulingPolicy::scheduleTeachingDepartmentId($rows[$period->id]->fresh()));
            $auditCount = SchedulingAuditLog::count();
            $this->activate($period); // Selecting the active period must not rewrite saved work.
            $this->assertSame($auditCount, SchedulingAuditLog::count());
            $this->assertSame($savedMappings, CourseTeachingAssignment::orderBy('id')->get()->toArray());
            foreach ($rows as $semesterId => $row) {
                $this->assertSame($savedAttributes[$semesterId], $row->fresh()->getAttributes());
            }
        }
    }

    public function test_different_semester_targets_are_isolated_and_a_retained_view_cannot_overwrite_the_active_one(): void
    {
        $old = $this->meeting($this->first);
        $this->assign($this->first);
        $oldView = $old->fresh()->course;
        $this->activate($this->second);
        $other = Departments::create(['department_name' => 'Other teaching office', 'department_code' => 'OTHER']);
        $payload = $this->payload($this->second);
        $payload['teaching_department_id'] = $other->id;
        $payload['teaching_program_id'] = null;
        $this->actingAs($this->sender)->postJson('/api/course-teaching-assignments/batch', $payload)->assertOk();
        $new = $this->meeting($this->second);
        $this->assertEqualsCanonicalizing([$old->id], Schedule::delegatedTo($this->target->id)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$new->id], Schedule::delegatedTo($other->id)->pluck('id')->all());
        $this->assertSame($this->targetProgram->id, $old->fresh()->load('course')->toArray()['course']['teaching_program_id']);
        try {
            $oldView->update(['teaching_department_id' => $this->source->id]);
            $this->fail('A retained course view was saved into another semester.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('historical', $exception->getMessage());
        }
        $this->assertSame($other->id, $this->course->fresh()->teaching_department_id);
        $this->assertSame($this->target->id, $this->course->fresh()->forSemester($this->first->id)->teaching_department_id);
        $this->activate($this->first);
        $this->assertSame($this->target->id, $this->course->fresh()->teaching_department_id);
    }

    public function test_assignment_and_projection_roll_back_together_if_mapping_persistence_fails(): void
    {
        Event::listen('eloquent.creating: '.CourseTeachingAssignment::class, fn () => throw new \RuntimeException('Injected mapping failure'));
        $this->actingAs($this->sender)->postJson('/api/course-teaching-assignments/batch', $this->payload($this->first))->assertStatus(500);
        Event::forget('eloquent.creating: '.CourseTeachingAssignment::class);
        $this->assertNull($this->course->fresh()->teaching_department_id);
        $this->assertSame(0, CourseTeachingAssignment::count());
        $this->assertSame(0, SystemNotification::count());
    }

    public function test_a_rendered_notification_failure_rolls_back_the_entire_assignment_request(): void
    {
        Event::listen('eloquent.created: '.SystemNotification::class, fn () => throw new \RuntimeException('Injected notification failure'));
        $this->actingAs($this->sender)->postJson('/api/course-teaching-assignments/batch', $this->payload($this->first))->assertStatus(500);
        Event::forget('eloquent.created: '.SystemNotification::class);
        $this->assertNull($this->course->fresh()->teaching_department_id);
        $this->assertSame(0, CourseTeachingAssignment::count());
        $this->assertSame(0, SystemNotification::count());
    }

    public function test_migration_preserves_effective_legacy_delegations_only_for_existing_work_and_the_active_semester(): void
    {
        $this->meeting($this->second);
        SchedulingAuditLog::create(['semester_id' => $this->second->id, 'action' => 'semester_ended']);
        $empty = Semester::create(['academic_year' => '2027-2028', 'semester' => '1st', 'is_active' => false]);
        $this->meeting($empty)->update(['status' => 'draft']);
        $migration = require database_path('migrations/2026_11_23_120000_create_course_teaching_assignments_table.php');
        $migration->down();
        DB::table('courses')->where('id', $this->course->id)->update(['teaching_department_id' => $this->target->id,
            'teaching_program_id' => $this->targetProgram->id, 'teaching_source_program_id' => $this->sourceProgram->id]);
        $migration->up();
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], CourseTeachingAssignment::pluck('semester_id')->all());
        $this->assertSame(2, CourseTeachingAssignment::where('is_legacy_snapshot', true)->count());
        $this->assertDatabaseMissing('course_teaching_assignments', ['semester_id' => $empty->id]);
    }

    private function meeting(Semester $semester): Schedule
    {
        $section = Sections::create(['section_name' => 'BSBA-'.$semester->id, 'year_level' => '1', 'semester' => $semester->semester,
            'semester_id' => $semester->id, 'department_id' => $this->source->id, 'program_id' => $this->sourceProgram->id, 'status' => 'active']);

        return Schedule::create(['semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $this->course->id,
            'department_id' => $this->source->id, 'program_id' => $this->sourceProgram->id, 'room_id' => $this->room->id,
            'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => 'faculty_assignment']);
    }

    private function payload(Semester $semester): array
    {
        return ['course_ids' => [$this->course->id], 'teaching_department_id' => $this->target->id,
            'teaching_program_id' => $this->targetProgram->id, 'expected_active_semester_id' => $semester->id];
    }

    private function assign(Semester $semester): void
    {
        $this->actingAs($this->sender)->postJson('/api/course-teaching-assignments/batch', $this->payload($semester))->assertOk();
    }

    private function activate(Semester $semester): void
    {
        $this->actingAs($this->vpaa)->patchJson('/api/semesters/'.$semester->id.'/activate')->assertOk();
    }
}
