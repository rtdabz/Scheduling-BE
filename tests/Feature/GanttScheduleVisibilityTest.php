<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GanttScheduleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('viewerRoles')]
    public function test_every_published_schedule_read_excludes_unapproved_rows_for_every_role(string $role, string $view): void
    {
        $f = $this->fixture($role);
        $semesterId = $f['semester']->id;
        $sectionId = $f['section']->id;

        foreach ([
            "/api/schedules?semester_id={$semesterId}&per_page=4",
            "/api/schedules/semester/{$semesterId}",
            "/api/schedules/section/{$sectionId}",
        ] as $url) {
            $rows = $this->actingAs($f['user'])
                ->getJson($url.(str_contains($url, '?') ? '&' : '?').'view='.$view)->assertOk()->json();
            $this->assertEqualsCanonicalizing($f['approvedIds'], array_column($rows, 'id'), $url);
        }

        $initial = $this->actingAs($f['user'])
            ->getJson('/api/initial-data?include=schedules&view='.$view)->assertOk();
        $this->assertEqualsCanonicalizing($f['approvedIds'], array_column($initial->json('schedules'), 'id'));

        if ($role !== 'vpaa') {
            $ordinary = $this->actingAs($f['user'])
                ->getJson("/api/schedules?semester_id={$semesterId}")->assertOk()->json();
            $this->assertContains('draft', array_column($ordinary, 'status'));
            $this->assertContains('submitted', array_column($ordinary, 'status'));
        }
    }

    public static function viewerRoles(): array
    {
        $cases = [];
        foreach (['secretary', 'director', 'program_head', 'dean', 'vpaa'] as $role) {
            foreach (['gantt', 'published'] as $view) {
                $cases[$role.' '.$view] = [$role, $view];
            }
        }

        return $cases;
    }

    public static function publishedViews(): array
    {
        return [['gantt'], ['published']];
    }

    #[DataProvider('publishedViews')]
    public function test_published_initial_data_filters_before_pagination_and_isolates_its_cached_payload(string $view): void
    {
        $f = $this->fixture('secretary');
        $this->actingAs($f['user']);

        // Warm the ordinary payload first: it must not be reused for an approved-only view.
        $ordinary = $this->getJson('/api/initial-data?include=schedules')->assertOk();
        $this->assertContains('draft', array_column($ordinary->json('schedules'), 'status'));

        $paged = $this->getJson('/api/initial-data?include=schedules&per_page=2&view='.$view)->assertOk();
        $this->assertSame(4, $paged->json('schedules.meta.total'));
        $this->assertSame(2, $paged->json('schedules.meta.last_page'));
        $this->assertCount(2, $paged->json('schedules.data'));
        $secondPage = $this->getJson('/api/initial-data?include=schedules&per_page=2&schedules_page=2&view='.$view)->assertOk();
        $this->assertEqualsCanonicalizing($f['approvedIds'], [
            ...array_column($paged->json('schedules.data'), 'id'),
            ...array_column($secondPage->json('schedules.data'), 'id'),
        ]);

        $compact = $this->getJson('/api/initial-data?include=schedules&schedule_limit=1&schedule_occupancy=1&view='.$view)->assertOk();
        $this->assertTrue($compact->json('schedules_truncated'));
        $this->assertCount(1, $compact->json('schedules'));
        $this->assertCount(3, $compact->json('schedule_occupancy'));
        $this->assertEqualsCanonicalizing($f['approvedIds'], [
            ...array_column($compact->json('schedules'), 'id'),
            ...array_column($compact->json('schedule_occupancy'), 'id'),
        ]);

        $selected = $this->getJson('/api/initial-data?include=schedules&schedule_limit=1&schedule_section_id='.$f['section']->id.'&view='.$view)->assertOk();
        $this->assertEqualsCanonicalizing($f['approvedIds'], array_column($selected->json('schedules'), 'id'));
        $this->assertContains('draft', array_column($this->getJson('/api/initial-data?include=schedules')->assertOk()->json('schedules'), 'status'));
    }

    #[DataProvider('publishedViews')]
    public function test_approval_queue_access_does_not_widen_the_published_view(string $view): void
    {
        $f = $this->fixture('vpaa');
        $f['user'] = $this->grantCapabilities($f['user'], ['schedule.view', 'schedule.approve_vpaa']);
        $this->actingAs($f['user']);

        foreach ([
            "/api/schedules?semester_id={$f['semester']->id}&approval_queue=1",
            "/api/schedules/semester/{$f['semester']->id}?approval_queue=1",
            "/api/schedules/section/{$f['section']->id}?approval_queue=1",
            '/api/initial-data?include=schedules&approval_queue=1',
        ] as $url) {
            $ordinary = $this->getJson($url)->assertOk()->json();
            $ordinary = $ordinary['schedules'] ?? $ordinary;
            $this->assertContains('approved_by_dean', array_column($ordinary, 'status'));

            $gantt = $this->getJson($url.'&view='.$view)->assertOk()->json();
            $gantt = $gantt['schedules'] ?? $gantt;
            $this->assertEqualsCanonicalizing($f['approvedIds'], array_column($gantt, 'id'));
        }
    }

    #[DataProvider('publishedViews')]
    public function test_published_query_keeps_department_semester_room_and_instructor_scope(string $view): void
    {
        $f = $this->fixture('secretary');
        $otherDepartment = Departments::create(['department_name' => 'Other Department', 'department_code' => 'OTH']);
        $previous = Semester::create(['academic_year' => '2025-2026', 'semester' => '1st', 'is_active' => false, 'is_enabled' => true]);
        $template = Schedule::findOrFail($f['approvedIds'][0])->getAttributes();
        unset($template['id'], $template['created_by'], $template['deleted_at'], $template['created_at'], $template['updated_at']);
        Schedule::create([...$template, 'department_id' => $otherDepartment->id]);
        Schedule::create([...$template, 'semester_id' => $previous->id]);
        Schedule::create([...$template, 'room_id' => null, 'faculty_id' => null]);

        $this->actingAs($f['user']);
        $rows = $this->getJson("/api/schedules?view={$view}&semester_id=active&room_id={$f['room']->id}&faculty_id={$f['faculty']->id}&department_id={$f['department']->id}")->assertOk()->json();
        $this->assertEqualsCanonicalizing($f['approvedIds'], array_column($rows, 'id'));
        $this->getJson("/api/schedules?view={$view}&department_id={$otherDepartment->id}")->assertForbidden();
    }

    private function fixture(string $role): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Gantt Department', 'department_code' => 'GNT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSGT', 'name' => 'Gantt']);
        $section = Sections::create([
            'section_name' => 'GT-1A', 'year_level' => '1', 'semester' => '1st', 'status' => 'active',
            'semester_id' => $semester->id, 'department_id' => $department->id, 'program_id' => $program->id,
        ]);
        $course = Course::create([
            'course_code' => 'GT101', 'course_name' => 'Gantt Course', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id, 'status' => 'active',
        ]);
        $room = Rooms::create(['room_code' => 'GT101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $faculty = Faculty::create(['first_name' => 'Ana', 'last_name' => 'Cruz', 'employment_type' => 'full-time', 'department_id' => $department->id, 'status' => 'active']);
        $user = $this->grantCapabilities(User::factory()->create([
            'role' => $role, 'department_id' => $role === 'vpaa' ? null : $department->id,
            'program_id' => $role === 'program_head' ? $program->id : null,
        ]), ['schedule.view']);

        // Put unapproved rows last so a limit applied before status filtering would hide approved meetings.
        $approvedIds = [];
        foreach (['approved', 'faculty_assignment', 'reassignment', 'finalized', 'draft', 'completed', 'submitted',
            'approved_by_dean', 'conditionally_approved', 'rejected_by_dean', 'rejected', 'revision'] as $index => $status) {
            $row = Schedule::create([
                'semester_id' => $semester->id, 'department_id' => $department->id, 'program_id' => $program->id,
                'section_id' => $section->id, 'course_id' => $course->id, 'room_id' => $room->id, 'faculty_id' => $faculty->id,
                'day' => 'Monday', 'start_time' => '08:00', 'end_time' => '09:00', 'mode' => 'on-site', 'status' => $status,
            ]);
            $row->timestamps = false;
            $row->created_at = now()->subMinutes(20 - $index);
            $row->save();
            if ($index < 4) {
                $approvedIds[] = $row->id;
            }
        }

        return compact('semester', 'department', 'program', 'section', 'course', 'room', 'faculty', 'user', 'approvedIds');
    }
}
