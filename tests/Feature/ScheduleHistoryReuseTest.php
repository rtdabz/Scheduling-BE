<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSplit;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schedule History: reuse a VPAA-approved schedule in the active semester, and export it as JSON.
 */
class ScheduleHistoryReuseTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'CIT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $curriculum = Curriculum::create(['name' => 'BSIT 2024', 'department_id' => $department->id, 'program_id' => $program->id, 'code' => 'BSIT-2024', 'effective_school_year' => '2024-2025', 'status' => 'active']);
        $past = Semester::create(['academic_year' => '2025-2026', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        $active = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $section = fn (Semester $semester) => Sections::create([
            'section_name' => 'BSIT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
        $pastSection = $section($past);
        $activeSection = $section($active);
        $course = Course::create([
            'course_code' => 'IT 101', 'course_name' => 'Programming', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id, 'status' => 'active',
        ]);
        DB::table('curriculum_course')->insert(['curriculum_id' => $curriculum->id, 'course_id' => $course->id, 'year_level' => '1', 'semester' => '1']);
        $room = Rooms::create(['room_code' => 'IT-201', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $faculty = Faculty::create(['first_name' => 'Ana', 'last_name' => 'Cruz', 'employment_type' => 'full-time', 'department_id' => $department->id, 'program_id' => $program->id, 'status' => 'active']);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'name' => 'Vice President']);

        $version = ScheduleHistoryVersion::create([
            'semester_id' => $past->id, 'department_id' => $department->id, 'actor_user_id' => $vpaa->id,
            'action' => 'schedule_approved_by_vpaa', 'source' => 'department_workflow',
        ]);
        ScheduleHistoryItem::create([
            'history_version_id' => $version->id,
            'original_schedule_id' => 900,
            'after_snapshot' => [
                'id' => 900, 'semester_id' => $past->id, 'section_id' => $pastSection->id, 'course_id' => $course->id,
                'curriculum_id' => $curriculum->id, 'faculty_id' => $faculty->id, 'room_id' => $room->id,
                'department_id' => $department->id, 'program_id' => $program->id, 'day' => 'Monday',
                'start_time' => '07:00:00', 'end_time' => '10:00:00', 'mode' => 'on-site', 'is_hybrid' => 0,
                'status' => 'faculty_assignment', 'split_group_id' => null, 'meeting_type' => null, 'meeting_index' => null,
            ],
            'snapshot_metadata' => ['section_name' => 'BSIT 1A', 'section_year_level' => 1, 'course_code' => 'IT 101', 'course_name' => 'Programming', 'faculty_name' => 'Ana Cruz', 'room_name' => 'IT-201'],
        ]);

        $this->ctx = compact('department', 'program', 'curriculum', 'past', 'active', 'pastSection', 'activeSection', 'course', 'room', 'faculty', 'vpaa', 'version') + [
            'secretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    public function test_reuse_creates_new_draft_rows_in_the_active_semester(): void
    {
        $version = $this->ctx['version'];
        $before = $version->items()->first()->toArray();

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$version->id}/reuse")
            ->assertCreated()
            ->assertJsonPath('section_count', 1);

        $created = Schedule::query()->whereKey($response->json('schedule_ids'))->get();
        $this->assertCount(1, $created);
        $row = $created->first();
        $this->assertNotSame(900, (int) $row->id);
        $this->assertSame('draft', $row->status);
        $this->assertSame((int) $this->ctx['active']->id, (int) $row->semester_id);
        $this->assertSame((int) $this->ctx['activeSection']->id, (int) $row->section_id);
        $this->assertSame((int) $this->ctx['curriculum']->id, (int) $row->curriculum_id);
        $this->assertSame((int) $this->ctx['room']->id, (int) $row->room_id);
        $this->assertNull($row->faculty_id, 'only the timetable is reused, never the instructor');
        $this->assertSame(['Monday', '07:00', '10:00'], [$row->day, substr($row->start_time, 0, 5), substr($row->end_time, 0, 5)]);

        // The history entry is untouched and the creation is audited.
        $this->assertSame($before, $version->items()->first()->toArray());
        $this->assertSame(1, ScheduleHistoryVersion::query()->whereKey($version->id)->count());
        $this->assertTrue(SchedulingAuditLog::query()->where('action', 'schedule_created')
            ->where('metadata->reused_history_version_id', $version->id)->exists());
    }

    public function test_a_different_academic_year_is_allowed_but_a_different_term_is_not(): void
    {
        Semester::query()->whereKey($this->ctx['active']->id)->update(['is_active' => false]);
        Semester::create(['academic_year' => '2026-2027', 'semester' => '2nd', 'is_active' => true, 'is_enabled' => true]);

        $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable()
            ->assertJsonPath('issues.0.check', 'semester');
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_reuse_recovers_a_legacy_hybrid_lecture_pair_after_the_originals_are_permanently_deleted(): void
    {
        $this->recordDeletedHybridMeetings([
            ['day' => 'Monday', 'start_time' => '07:00:00', 'end_time' => '08:30:00', 'mode' => 'online', 'room_id' => null, 'meeting_type' => 'lecture'],
            ['day' => 'Wednesday', 'start_time' => '07:00:00', 'end_time' => '08:30:00', 'mode' => 'on-site', 'room_id' => $this->ctx['room']->id, 'meeting_type' => 'lecture'],
        ]);
        $before = $this->ctx['version']->items()->get()->toArray();

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertCreated()
            ->assertJsonPath('section_count', 1);

        $rows = Schedule::query()->whereKey($response->json('schedule_ids'))->orderBy('id')->get();
        $this->assertReusedHybridPair($rows);
        $this->assertSame(['lecture', 'lecture'], $rows->pluck('meeting_type')->all());
        $this->assertSame(['Monday', 'Wednesday'], $rows->pluck('day')->all());
        $this->assertSame(['07:00', '07:00'], $rows->map(fn (Schedule $row) => substr($row->start_time, 0, 5))->all());
        $this->assertSame(['08:30', '08:30'], $rows->map(fn (Schedule $row) => substr($row->end_time, 0, 5))->all());
        $this->assertSame([null, (int) $this->ctx['room']->id], $rows->pluck('room_id')->all());
        $this->assertSame($before, $this->ctx['version']->items()->get()->toArray());
    }

    public function test_reuse_recovers_legacy_integrated_hybrid_meeting_types_from_recorded_hours(): void
    {
        $this->ctx['course']->update(['lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        DB::table('curriculum_course')
            ->where('curriculum_id', $this->ctx['curriculum']->id)
            ->where('course_id', $this->ctx['course']->id)
            ->update([
                'lecture_units' => 2, 'lab_units' => 1, 'lecture_minutes' => 120, 'laboratory_minutes' => 180,
                'conversion_snapshot' => json_encode(['version' => 1, 'source' => 'custom', 'lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180]),
            ]);
        $lab = Rooms::create([
            'room_code' => 'IT-LAB', 'building' => 'IT Building', 'room_type' => 'laboratory',
            'status' => 'available', 'department_id' => $this->ctx['department']->id,
        ]);
        $this->recordDeletedHybridMeetings([
            ['day' => 'Monday', 'start_time' => '07:00:00', 'end_time' => '09:00:00', 'mode' => 'online', 'room_id' => null, 'meeting_type' => 'lecture'],
            ['day' => 'Wednesday', 'start_time' => '13:00:00', 'end_time' => '16:00:00', 'mode' => 'on-site', 'room_id' => $lab->id, 'meeting_type' => 'laboratory'],
        ]);

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse");
        $this->assertSame(201, $response->status(), $response->getContent());

        $rows = Schedule::query()->whereKey($response->json('schedule_ids'))->orderBy('id')->get();
        $this->assertReusedHybridPair($rows);
        $this->assertSame(['lecture', 'laboratory'], $rows->pluck('meeting_type')->all());
        $this->assertSame([null, (int) $lab->id], $rows->pluck('room_id')->all());
        $this->assertSame(['07:00', '13:00'], $rows->map(fn (Schedule $row) => substr($row->start_time, 0, 5))->all());
        $this->assertSame(['09:00', '16:00'], $rows->map(fn (Schedule $row) => substr($row->end_time, 0, 5))->all());
    }

    public function test_reuse_refuses_an_incomplete_legacy_hybrid_group_without_writing_any_rows(): void
    {
        $this->recordDeletedHybridMeetings([
            ['day' => 'Monday', 'start_time' => '07:00:00', 'end_time' => '08:30:00', 'mode' => 'online', 'room_id' => null, 'meeting_type' => 'lecture'],
        ]);
        $before = $this->ctx['version']->items()->get()->toArray();
        $auditCount = SchedulingAuditLog::query()->count();

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable()
            ->assertJsonPath('issues.0.check', 'snapshot');

        $message = strtolower($response->json('issues.0.message'));
        $this->assertStringContainsString('meeting', $message);
        $this->assertStringContainsString('schedule builder', $message);
        $this->assertSame(0, Schedule::withTrashed()->count());
        $this->assertSame(0, ScheduleSplit::withTrashed()->count());
        $this->assertSame($auditCount, SchedulingAuditLog::query()->count());
        $this->assertSame($before, $this->ctx['version']->items()->get()->toArray());
    }

    public function test_reuse_does_not_infer_split_metadata_when_the_snapshot_explicitly_records_null(): void
    {
        $this->recordDeletedHybridMeetings([
            ['day' => 'Monday', 'start_time' => '07:00:00', 'end_time' => '08:30:00', 'mode' => 'online', 'room_id' => null, 'meeting_type' => 'lecture'],
            ['day' => 'Wednesday', 'start_time' => '07:00:00', 'end_time' => '08:30:00', 'mode' => 'on-site', 'room_id' => $this->ctx['room']->id, 'meeting_type' => 'lecture'],
        ], explicitNulls: true);

        $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable();

        $this->assertSame(0, Schedule::withTrashed()->count());
        $this->assertSame(0, ScheduleSplit::withTrashed()->count());
    }

    public function test_reuse_preserves_recorded_hybrid_meetings_when_the_original_splits_no_longer_exist(): void
    {
        $this->recordDeletedHybridMeetings([
            ['day' => 'Monday', 'start_time' => '07:00:00', 'end_time' => '08:30:00', 'mode' => 'online', 'room_id' => null, 'meeting_type' => 'lecture'],
            ['day' => 'Wednesday', 'start_time' => '07:00:00', 'end_time' => '08:30:00', 'mode' => 'on-site', 'room_id' => $this->ctx['room']->id, 'meeting_type' => 'lecture'],
        ], legacy: false);

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertCreated();

        $rows = Schedule::query()->whereKey($response->json('schedule_ids'))->orderBy('id')->get();
        $this->assertReusedHybridPair($rows);
        $this->assertSame(['lecture', 'lecture'], $rows->pluck('meeting_type')->all());
    }

    public function test_reuse_requires_the_section_in_the_active_semester(): void
    {
        $this->ctx['activeSection']->delete();

        $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable()
            ->assertJsonPath('issues.0.check', 'sections');
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_reuse_requires_the_same_curriculum(): void
    {
        $other = Curriculum::create(['name' => 'BSIT 2026', 'department_id' => $this->ctx['department']->id, 'program_id' => $this->ctx['program']->id, 'code' => 'BSIT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $this->ctx['activeSection']->update(['curriculum_id' => $other->id]);

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable()
            ->assertJsonPath('issues.0.check', 'curriculum');
        $this->assertStringContainsString('BSIT-2024', $response->json('issues.0.message'));
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_reuse_reports_unavailable_rooms(): void
    {
        $this->ctx['room']->update(['status' => 'not available']);

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable();
        $this->assertSame(['facilities'], collect($response->json('issues'))->pluck('check')->unique()->values()->all());
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_the_original_instructor_does_not_affect_reuse(): void
    {
        // The historical instructor has since left; reuse ignores instructors entirely.
        $this->ctx['faculty']->update(['status' => 'inactive']);

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertCreated();
        $this->assertSame([null], Schedule::query()->whereKey($response->json('schedule_ids'))->pluck('faculty_id')->all());
    }

    public function test_reuse_refuses_room_conflicts_with_the_active_semester(): void
    {
        $otherSection = Sections::create([
            'section_name' => 'BSIT 1B', 'year_level' => '1', 'semester' => '1st', 'department_id' => $this->ctx['department']->id,
            'program_id' => $this->ctx['program']->id, 'curriculum_id' => $this->ctx['curriculum']->id, 'semester_id' => $this->ctx['active']->id, 'status' => 'active',
        ]);
        $existing = Schedule::create([
            'semester_id' => $this->ctx['active']->id, 'section_id' => $otherSection->id, 'course_id' => $this->ctx['course']->id,
            'curriculum_id' => $this->ctx['curriculum']->id, 'faculty_id' => $this->ctx['faculty']->id, 'room_id' => $this->ctx['room']->id,
            'department_id' => $this->ctx['department']->id, 'program_id' => $this->ctx['program']->id, 'day' => 'Monday',
            'start_time' => '08:00', 'end_time' => '11:00', 'mode' => 'on-site', 'status' => 'draft',
        ]);

        $response = $this->actingAs($this->ctx['secretary'], 'sanctum')
            ->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable();
        $this->assertContains('conflicts', collect($response->json('issues'))->pluck('check')->all());
        $this->assertSame([(int) $existing->id], Schedule::query()->pluck('id')->map('intval')->all());
    }

    public function test_reuse_never_duplicates_a_class_the_section_already_has(): void
    {
        $this->actingAs($this->ctx['secretary'], 'sanctum')->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")->assertCreated();

        $this->postJson("/api/schedule-history/{$this->ctx['version']->id}/reuse")
            ->assertUnprocessable()
            ->assertJsonPath('issues.0.check', 'conflicts')
            ->assertJsonPath('issues.0.code', 'already_scheduled')
            ->assertJsonPath('issues.0.section_name', 'BSIT 1A')
            ->assertJsonPath('issues.0.course_code', 'IT 101')
            ->assertJsonPath('issues.0.message', 'IT 101 is already scheduled for BSIT 1A in the active semester.');
        $this->assertSame(1, Schedule::query()->count());
    }

    public function test_only_vpaa_approved_entries_can_be_reused_or_exported(): void
    {
        $dean = ScheduleHistoryVersion::create(['semester_id' => $this->ctx['past']->id, 'department_id' => $this->ctx['department']->id, 'action' => 'schedule_approved_by_dean', 'source' => 'department_workflow']);

        $this->actingAs($this->ctx['secretary'], 'sanctum')->postJson("/api/schedule-history/{$dean->id}/reuse")->assertNotFound();
        $this->getJson("/api/schedule-history/{$dean->id}/export")->assertNotFound();
    }

    public function test_reuse_is_limited_to_schedule_builders_of_the_same_department(): void
    {
        $version = $this->ctx['version'];
        $this->actingAs($this->ctx['vpaa'], 'sanctum')->postJson("/api/schedule-history/{$version->id}/reuse")->assertForbidden();

        $otherDepartment = Departments::create(['department_name' => 'Education', 'department_code' => 'CED']);
        Program::create(['department_id' => $otherDepartment->id, 'code' => 'BSED', 'name' => 'Education']);
        $outsider = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $otherDepartment->id]));
        $this->actingAs($outsider, 'sanctum')->postJson("/api/schedule-history/{$version->id}/reuse")->assertNotFound();
        $this->assertSame(0, Schedule::query()->count());
    }

    public function test_export_downloads_a_readable_versioned_json_snapshot_without_reusing(): void
    {
        $this->grantCapabilities($this->ctx['vpaa'], ['schedule.view']);
        $response = $this->actingAs($this->ctx['vpaa']->fresh(), 'sanctum')
            ->get("/api/schedule-history/{$this->ctx['version']->id}/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $this->assertStringContainsString('attachment; filename="wicars-schedule-cit-2025-2026-1st-entry-', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("\n    \"schema_version\"", $response->getContent(), 'pretty-printed');
        $json = json_decode($response->getContent(), true);
        $this->assertSame('1.0', $json['schema_version']);
        $this->assertSame('CIT', $json['department']['code']);
        $this->assertSame(['semester_id' => $this->ctx['past']->id, 'academic_year' => '2025-2026', 'semester' => '1st'], $json['academic_period']);
        $this->assertSame('BSIT-2024', $json['curricula'][0]['code']);
        $this->assertSame('approved_by_vpaa', $json['approval']['status']);
        $this->assertSame('Vice President', $json['approval']['approved_by']['name']);
        $class = $json['sections'][0]['classes'][0];
        $this->assertSame('BSIT 1A', $json['sections'][0]['name']);
        $this->assertSame(['IT 101', 'Ana Cruz', 'IT-201', 'IT Building', 'Monday', '07:00', '10:00', 'on-site', 'lecture'], [
            $class['course']['code'], $class['instructor']['name'], $class['room']['code'], $class['room']['building'],
            $class['day'], $class['start_time'], $class['end_time'], $class['delivery_mode'], $class['class_type'],
        ]);
        $this->assertSame(900, $class['schedule_id']);
        $this->assertSame(0, Schedule::query()->count(), 'export never creates a schedule');
    }

    private function recordDeletedHybridMeetings(array $meetings, bool $legacy = true, bool $explicitNulls = false): void
    {
        $item = $this->ctx['version']->items()->first();
        $base = $item->after_snapshot;
        $metadata = $item->snapshot_metadata + [
            'lecture_hours' => $this->ctx['course']->lecture_hours,
            'lab_hours' => $this->ctx['course']->lab_hours,
        ];
        // Build another recorded version; existing evidence is append-only even in fixtures.
        $this->ctx['version'] = ScheduleHistoryVersion::create($this->ctx['version']->only([
            'semester_id', 'department_id', 'actor_user_id', 'action', 'source',
        ]));

        foreach ($meetings as $index => $meeting) {
            $snapshot = array_replace($base, $meeting, [
                'is_hybrid' => true, 'split_group_id' => 'historical-hybrid-group', 'meeting_index' => $index + 1,
            ]);
            $original = Schedule::create($snapshot);
            $snapshot['id'] = $original->id;
            if ($legacy) {
                unset($snapshot['split_group_id'], $snapshot['meeting_type'], $snapshot['meeting_index']);
            }
            if ($explicitNulls) {
                $snapshot += ['split_group_id' => null, 'meeting_type' => null, 'meeting_index' => null];
            }
            ScheduleHistoryItem::create([
                'history_version_id' => $this->ctx['version']->id,
                'original_schedule_id' => $original->id,
                'after_snapshot' => $snapshot,
                'snapshot_metadata' => $metadata,
            ]);
            $original->deletePermanently();
        }

        $this->assertSame(0, Schedule::withTrashed()->count());
        $this->assertSame(0, ScheduleSplit::withTrashed()->count());
    }

    private function assertReusedHybridPair(Collection $rows): void
    {
        $this->assertCount(2, $rows);
        $this->assertCount(1, $rows->pluck('split_group_id')->unique());
        $this->assertNotEmpty($rows->first()->split_group_id);
        $this->assertNotSame('historical-hybrid-group', $rows->first()->split_group_id);
        $this->assertSame([1, 2], $rows->pluck('meeting_index')->all());
        $this->assertSame(['online', 'on-site'], $rows->pluck('mode')->all());
        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->is_hybrid);
            $this->assertSame('draft', $row->status);
            $this->assertSame((int) $this->ctx['active']->id, (int) $row->semester_id);
            $this->assertSame((int) $this->ctx['activeSection']->id, (int) $row->section_id);
            $this->assertNull($row->faculty_id);
        }
        $this->assertSame(2, ScheduleSplit::query()->count());
    }
}
