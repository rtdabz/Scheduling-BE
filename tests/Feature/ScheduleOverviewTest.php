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
use Tests\TestCase;

/**
 * The All Schedules overview reports institution-wide totals, so the numbers it
 * shows have to come from the whole semester rather than from however many rows a
 * bounded payload happened to carry.
 */
class ScheduleOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_counts_classes_meetings_and_gaps_across_the_whole_semester(): void
    {
        $context = $this->scaffold();

        // One MWF class: three meeting rows, one class.
        foreach (['Monday', 'Wednesday', 'Friday'] as $day) {
            Schedule::create($this->meeting($context, ['day' => $day]));
        }

        // A second class in the same section, unassigned faculty and room.
        Schedule::create($this->meeting($context, [
            'course_id' => $context['second_course']->id,
            'day' => 'Tuesday',
            'start_time' => '13:00:00',
            'end_time' => '15:00:00',
            'faculty_id' => null,
            'room_id' => null,
        ]));

        $overview = $this->overview();
        $department = $this->departmentRow($overview, 'CIT');

        $this->assertSame(2, $department['classes'], 'A class is a section/course pair, not a meeting row.');
        $this->assertSame(4, $department['meetings']);
        $this->assertSame(1, $department['unassigned_faculty']);
        $this->assertSame(1, $department['unassigned_rooms']);
        // Two sections exist; only one has been scheduled.
        $this->assertSame(2, $department['sections_total']);
        $this->assertSame(1, $department['sections_scheduled']);
    }

    public function test_an_online_meeting_without_a_room_is_not_counted_as_a_gap(): void
    {
        $context = $this->scaffold();

        Schedule::create($this->meeting($context, [
            'day' => 'Monday',
            'mode' => 'online',
            'room_id' => null,
        ]));

        $department = $this->departmentRow($this->overview(), 'CIT');

        $this->assertSame(0, $department['unassigned_rooms'], 'Online delivery needs no room.');
    }

    public function test_overlapping_meetings_are_counted_once_per_meeting_and_split_by_kind(): void
    {
        $context = $this->scaffold();

        // Same section, same faculty, same room, same hour: one overlap that
        // trips all three kinds at once, across two meetings.
        Schedule::create($this->meeting($context, ['day' => 'Monday']));
        Schedule::create($this->meeting($context, [
            'course_id' => $context['second_course']->id,
            'day' => 'Monday',
        ]));

        $department = $this->departmentRow($this->overview(), 'CIT');

        $this->assertSame(2, $department['conflicts']['faculty']);
        $this->assertSame(2, $department['conflicts']['room']);
        $this->assertSame(2, $department['conflicts']['section']);
        $this->assertSame(
            2,
            $department['conflicts']['total'],
            'A meeting in three kinds of conflict is still one meeting in conflict.',
        );
    }

    public function test_meetings_that_only_touch_at_the_boundary_do_not_conflict(): void
    {
        $context = $this->scaffold();

        Schedule::create($this->meeting($context, ['day' => 'Monday', 'start_time' => '09:00:00', 'end_time' => '10:00:00']));
        Schedule::create($this->meeting($context, [
            'course_id' => $context['second_course']->id,
            'day' => 'Monday',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]));

        $department = $this->departmentRow($this->overview(), 'CIT');

        $this->assertSame(0, $department['conflicts']['total'], 'Back-to-back classes are not an overlap.');
    }

    public function test_a_dean_sees_only_their_own_department(): void
    {
        $context = $this->scaffold();
        Schedule::create($this->meeting($context, ['day' => 'Monday']));

        $other = Departments::create([
            'department_name' => 'College of Business Administration',
            'department_code' => 'CBA',
        ]);
        Program::create(['department_id' => $other->id, 'code' => 'BSBA', 'name' => 'Business Administration']);
        Sections::create([
            'section_name' => 'BA 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $other->id, 'semester_id' => $context['semester']->id, 'status' => 'active',
        ]);

        $dean = $this->grantCapabilities(User::factory()->create([
            'role' => 'dean',
            'department_id' => $context['department']->id,
        ]));

        $response = $this->actingAs($dean)->getJson('/api/departments/schedule-overview');

        $response->assertOk();
        $this->assertSame(['CIT'], collect($response->json('departments'))->pluck('code')->all());
    }

    public function test_sections_are_nested_so_the_drill_down_needs_no_second_request(): void
    {
        $context = $this->scaffold();
        Schedule::create($this->meeting($context, ['day' => 'Monday']));
        Schedule::create($this->meeting($context, ['day' => 'Wednesday']));

        $department = $this->departmentRow($this->overview(), 'CIT');
        $section = collect($department['sections'])->firstWhere('code', 'IT 1A');

        $this->assertNotNull($section);
        $this->assertSame(2, $section['meetings']);
        $this->assertSame(1, $section['classes']);
        $this->assertSame(['Monday' => 1, 'Wednesday' => 1], $section['day_load']);
        $this->assertSame('approved', $section['status']);
    }

    public function test_the_vpaa_does_not_see_meetings_it_has_not_approved(): void
    {
        $context = $this->scaffold();

        // Still in the department's hands, and one cohort the Dean endorsed but
        // the VPAA has not acted on yet: neither belongs in the VPAA portal.
        Schedule::create($this->meeting($context, ['day' => 'Monday', 'status' => 'draft']));
        Schedule::create($this->meeting($context, [
            'course_id' => $context['second_course']->id,
            'day' => 'Tuesday',
            'status' => 'approved_by_dean',
        ]));

        $department = $this->departmentRow($this->overview(), 'CIT');

        $this->assertSame(0, $department['meetings']);
        $this->assertSame(0, $department['classes']);
        $this->assertSame(0, $department['sections_scheduled']);
        $this->assertSame(2, $department['sections_total'], 'The sections themselves are still listed.');
    }

    public function test_a_dean_still_sees_its_own_unapproved_meetings(): void
    {
        $context = $this->scaffold();
        Schedule::create($this->meeting($context, ['day' => 'Monday', 'status' => 'draft']));

        $dean = $this->grantCapabilities(User::factory()->create([
            'role' => 'dean',
            'department_id' => $context['department']->id,
        ]));

        $response = $this->actingAs($dean)->getJson('/api/departments/schedule-overview');
        $response->assertOk();

        $department = $this->departmentRow($response->json(), 'CIT');
        $this->assertSame(1, $department['meetings']);
        $this->assertSame('draft', $department['status']);
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $vpaa = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa', 'department_id' => null]));

        $response = $this->actingAs($vpaa)->getJson('/api/departments/schedule-overview');
        $response->assertOk();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function departmentRow(array $overview, string $code): array
    {
        $row = collect($overview['departments'])->firstWhere('code', $code);
        $this->assertNotNull($row, 'No overview row for department '.$code.'.');

        return $row;
    }

    /** @param array<string, mixed> $overrides */
    private function meeting(array $context, array $overrides = []): array
    {
        return array_merge([
            'semester_id' => $context['semester']->id,
            'section_id' => $context['section']->id,
            'course_id' => $context['course']->id,
            'faculty_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'department_id' => $context['department']->id,
            'day' => 'Tuesday',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'mode' => 'on-site',
            // VPAA-approved, because most cases here read the overview as the
            // VPAA and its portal reports approved meetings only.
            'status' => 'faculty_assignment',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function scaffold(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id,
            'semester_id' => $semester->id, 'status' => 'active',
        ]);

        // Left unscheduled on purpose: it is what makes sections_scheduled
        // differ from sections_total.
        Sections::create([
            'section_name' => 'IT 1B', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'program_id' => $program->id,
            'semester_id' => $semester->id, 'status' => 'active',
        ]);

        $room = Rooms::create([
            'room_code' => 'LAB-1', 'building' => 'Main', 'room_type' => 'laboratory',
            'status' => 'available', 'department_id' => $department->id, 'max_concurrent_classes' => 1,
        ]);

        $faculty = Faculty::create([
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'employment_type' => 'full-time', 'max_units' => 18,
            'department_id' => $department->id, 'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'IT 101', 'course_name' => 'Programming 1',
            'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'laboratory',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);

        $secondCourse = Course::create([
            'course_code' => 'IT 102', 'course_name' => 'Web Systems',
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'section' => $section,
            'room' => $room,
            'faculty' => $faculty,
            'course' => $course,
            'second_course' => $secondCourse,
        ];
    }
}
