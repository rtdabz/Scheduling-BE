<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Term isolation, database pagination, narrowed submission history and selected-section completeness
 * for /initial-data.
 */
class InitialDataCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Semester $semester;

    private Departments $department;

    private Program $program;

    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true,
        ]);
        $this->department = Departments::create(['department_name' => 'Completeness Dept', 'department_code' => 'CMP']);
        $this->program = Program::create(['department_id' => $this->department->id, 'code' => 'BSCP', 'name' => 'Completeness']);
        $this->secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]);
    }

    public function test_sections_from_another_academic_year_with_the_same_label_are_left_out(): void
    {
        $previousYear = Semester::create([
            'academic_year' => '2025-2026', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true,
        ]);
        $current = $this->section('CMP-1A');
        $this->section('CMP-OLD', ['semester_id' => $previousYear->id]);
        // Sections without a semester (still matched by the query for older databases) cannot be built
        // here: the current schema makes `sections.semester_id` a required foreign key.

        $names = collect($this->actingAs($this->secretary)->getJson('/api/initial-data?include=sections')
            ->assertOk()->json('sections'))->pluck('section_name')->all();

        $this->assertSame([$current->section_name], $names);
    }

    public function test_paginated_lists_are_cut_by_the_database_and_report_true_totals(): void
    {
        foreach (range(1, 7) as $index) {
            $this->section(sprintf('CMP-%02d', $index));
        }
        $selects = [];
        DB::listen(function ($query) use (&$selects): void {
            if (str_starts_with($query->sql, 'select * from "sections"')) {
                $selects[] = $query->sql;
            }
        });

        $first = $this->actingAs($this->secretary)->getJson('/api/initial-data?include=sections&per_page=3')->assertOk();
        $third = $this->actingAs($this->secretary)->getJson('/api/initial-data?include=sections&per_page=3&sections_page=3')->assertOk();

        $this->assertSame(['page' => 1, 'per_page' => 3, 'total' => 7, 'last_page' => 3], $first->json('sections.meta'));
        $this->assertCount(3, $first->json('sections.data'));
        $this->assertSame(['page' => 3, 'per_page' => 3, 'total' => 7, 'last_page' => 3], $third->json('sections.meta'));
        $this->assertCount(1, $third->json('sections.data'));
        $this->assertEmpty(array_intersect(
            collect($first->json('sections.data'))->pluck('id')->all(),
            collect($third->json('sections.data'))->pluck('id')->all(),
        ));
        $this->assertNotEmpty($selects);
        foreach ($selects as $sql) {
            $this->assertStringContainsString('limit 3', $sql, 'Sections must be paginated in SQL, not after loading all of them.');
        }
    }

    public function test_scheduler_refresh_loads_only_each_sections_latest_submission(): void
    {
        $section = $this->section('CMP-1A');
        $course = $this->course('CMP101');
        $this->meeting($section, $course, 'Monday', 'submitted');
        $older = $this->submission(1, 'rejected', [$section->id]);
        $latest = $this->submission(2, 'submitted', [$section->id]);

        $refresh = $this->actingAs($this->secretary)->getJson('/api/initial-data?include=schedules')->assertOk();

        $this->assertSame($latest->id, $refresh->json('schedules.0.schedule_submission_id'));
        $this->assertSame($latest->id, $refresh->json('schedules.0.signatories_submission_id'));
        $this->assertSame([(string) $latest->id], array_map('strval', array_keys($refresh->json('schedule_signatories'))));
        $this->assertArrayNotHasKey('schedule_submissions', $refresh->json());

        // The approval screens still receive the whole history.
        $full = $this->actingAs($this->secretary)->getJson('/api/initial-data')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$older->id, $latest->id],
            collect($full->json('schedule_submissions'))->pluck('id')->all(),
        );
        $this->assertNotNull(collect($full->json('schedule_submissions'))->firstWhere('id', $older->id)['signatories'] ?? null);
    }

    public function test_the_selected_section_and_conflict_occupancy_stay_complete_beyond_2000_meetings(): void
    {
        $busy = $this->section('CMP-BUSY');
        $selected = $this->section('CMP-SEL');
        $course = $this->course('CMP201');
        $room = Rooms::create(['room_code' => 'CMP-R1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $this->department->id]);

        // The selected section's meetings are the oldest, so the newest-first cut drops all of them.
        $selectedIds = [];
        foreach (['Monday', 'Tuesday', 'Wednesday'] as $index => $day) {
            $selectedIds[] = DB::table('schedules')->insertGetId($this->row($selected, $course, $day, now()->subYears(1)->addMinutes($index), $room->id));
        }
        $rows = [];
        for ($i = 0; $i < 2050; $i++) {
            $rows[] = $this->row($busy, $course, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'][$i % 5], now()->subMinutes($i), $room->id);
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('schedules')->insert($chunk);
        }

        $capped = $this->actingAs($this->secretary)
            ->getJson('/api/initial-data?include=schedules&schedule_limit=2000')->assertOk();
        $this->assertTrue($capped->json('schedules_truncated'));
        $this->assertCount(2000, $capped->json('schedules'));
        $this->assertEmpty(array_intersect($selectedIds, collect($capped->json('schedules'))->pluck('id')->all()));
        $this->assertArrayNotHasKey('schedule_occupancy', $capped->json());

        $complete = $this->actingAs($this->secretary)->getJson(
            '/api/initial-data?include=schedules&schedule_limit=2000&schedule_occupancy=1&schedule_section_id='.$selected->id,
        )->assertOk();

        $full = collect($complete->json('schedules'));
        $occupancy = collect($complete->json('schedule_occupancy'));
        $this->assertTrue($complete->json('schedules_truncated'));
        $this->assertCount(2003, $full);
        $this->assertEqualsCanonicalizing($selectedIds, $full->where('section_id', $selected->id)->pluck('id')->all());
        $this->assertSame('CMP201', $full->firstWhere('id', $selectedIds[0])['course']['course_code']);

        // Every visible meeting is accounted for exactly once, so client conflict checks see them all.
        $this->assertCount(2053, $full->pluck('id')->concat($occupancy->pluck('id'))->unique());
        $this->assertCount(50, $occupancy);
        $this->assertSame(
            ['course_id', 'day', 'delegation_returned_from_department_id', 'department_id', 'end_time', 'faculty_assignment_done', 'faculty_id', 'id', 'is_hybrid',
                'meeting_index', 'meeting_type', 'mode', 'preferred_pattern', 'room', 'room_id', 'section_id',
                'section_teaching_department_id', 'section_teaching_program_id',
                'semester_id', 'split_group_id', 'start_time', 'status'],
            collect(array_keys($occupancy->first()))->sort()->values()->all(),
        );
        $this->assertSame($room->id, $occupancy->first()['room_id']);
    }

    public function test_occupancy_hides_a_borrowed_classs_instructor_like_the_full_rows(): void
    {
        $section = $this->section('CMP-1A');
        $teaching = Departments::create(['department_name' => 'Teaching Dept', 'department_code' => 'TCH']);
        $borrowed = $this->course('TCH101', ['teaching_department_id' => $teaching->id]);
        $own = $this->course('CMP101');
        $instructor = Faculty::create([
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'department_id' => $teaching->id,
            'employment_type' => 'full-time', 'status' => 'active',
        ]);
        DB::table('schedules')->insert($this->row($section, $borrowed, 'Monday', now()->subDay(), null, ['faculty_id' => $instructor->id]));
        DB::table('schedules')->insert($this->row($section, $own, 'Tuesday', now(), null));

        $occupancy = collect($this->actingAs($this->secretary)
            ->getJson('/api/initial-data?include=schedules&schedule_limit=1&schedule_occupancy=1')
            ->assertOk()->json('schedule_occupancy'));

        $this->assertCount(1, $occupancy);
        $this->assertSame($borrowed->id, $occupancy->first()['course_id']);
        $this->assertNull($occupancy->first()['faculty_id']);
    }

    private function section(string $name, array $overrides = []): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'program_id' => $this->program->id,
            'semester_id' => $this->semester->id, 'status' => 'active',
            ...$overrides,
        ]);
    }

    private function course(string $code, array $overrides = []): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => $code,
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'status' => 'active',
            ...$overrides,
        ]);
    }

    private function meeting(Sections $section, Course $course, string $day, string $status): Schedule
    {
        return Schedule::create([
            'semester_id' => $this->semester->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'department_id' => $this->department->id, 'day' => $day,
            'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'online', 'status' => $status,
        ]);
    }

    private function row(Sections $section, Course $course, string $day, $createdAt, ?int $roomId, array $overrides = []): array
    {
        return [
            'semester_id' => $this->semester->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'department_id' => $this->department->id, 'room_id' => $roomId, 'day' => $day,
            'start_time' => '08:00:00', 'end_time' => '09:00:00',
            'mode' => $roomId === null ? 'online' : 'on-site', 'status' => 'draft',
            'faculty_assignment_done' => false,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
            ...$overrides,
        ];
    }

    /** @param  list<int>  $sectionIds */
    private function submission(int $revision, string $status, array $sectionIds): ScheduleSubmission
    {
        $submission = ScheduleSubmission::create([
            'department_id' => $this->department->id, 'semester_id' => $this->semester->id,
            'revision_number' => $revision, 'status' => $status,
            'submitted_by' => $this->secretary->id, 'submitted_at' => now(),
            'section_count' => count($sectionIds), 'subject_count' => 1,
        ]);
        foreach ($sectionIds as $sectionId) {
            DB::table('schedule_submission_sections')->insert([
                'schedule_submission_id' => $submission->id, 'section_id' => $sectionId, 'state' => 'active',
            ]);
        }

        return $submission;
    }
}
