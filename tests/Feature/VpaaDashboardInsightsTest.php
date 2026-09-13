<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The VPAA dashboard's institution-wide aggregates.
 *
 * These count over every meeting in the active semester, which is what /initial-data
 * cannot do: it caps its schedules array, so anything measured from that payload
 * understates the campus.
 */
class VpaaDashboardInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_virtual_rooms_stay_out_of_the_campus_inventory(): void
    {
        $fixture = $this->fixture();

        // ONLINE is a delivery mode standing in for a place, so counting it as a
        // room would overstate the inventory and dilute every utilisation figure.
        $this->meeting($fixture, 'ccsSection', 'ccs', 'Monday', '08:00:00', '09:30:00', 'approved', $fixture['onlineRoom']->id);

        $response = $this->actingAs($fixture['vpaa'])->getJson('/api/vpaa/dashboard-insights');

        $response->assertOk();
        $this->assertSame(1, $response->json('utilization.rooms_total'));
        // The online meeting books no physical room, so nothing is in use.
        $this->assertSame(0, $response->json('utilization.rooms_in_use'));
    }

    public function test_it_measures_room_load_against_campus_operating_hours(): void
    {
        $fixture = $this->fixture();
        $this->meeting($fixture, 'ccsSection', 'ccs', 'Monday', '08:00:00', '09:30:00');

        $response = $this->actingAs($fixture['vpaa'])->getJson('/api/vpaa/dashboard-insights');

        $response->assertOk();
        $this->assertSame(1, $response->json('utilization.rooms_in_use'));
        $this->assertSame('Shared Hall', $response->json('utilization.buildings.0.building'));
        $this->assertSame(1, $response->json('utilization.buildings.0.meetings'));
        $this->assertSame(1.5, $response->json('utilization.buildings.0.booked_hours'));
    }

    public function test_it_marks_the_busiest_hour_of_the_week(): void
    {
        $fixture = $this->fixture();
        $this->meeting($fixture, 'ccsSection', 'ccs', 'Monday', '08:00:00', '09:30:00');

        $response = $this->actingAs($fixture['vpaa'])->getJson('/api/vpaa/dashboard-insights');

        $response->assertOk();
        $this->assertSame('Monday', $response->json('peak_load.peak_day'));
        $this->assertSame(1, $response->json('peak_load.peak'));
        // 08:00-09:30 spans two hour columns, so both are occupied.
        $this->assertSame(8, $response->json('peak_load.peak_hour'));
    }

    public function test_it_counts_classes_with_no_instructor(): void
    {
        $fixture = $this->fixture();
        $this->meeting($fixture, 'ccsSection', 'ccs', 'Monday', '08:00:00', '09:30:00');

        $response = $this->actingAs($fixture['vpaa'])->getJson('/api/vpaa/dashboard-insights');

        $response->assertOk();
        $this->assertSame(1, $response->json('coverage.classes_without_instructor'));
        $this->assertSame(1, $response->json('coverage.departments_with_gaps'));
    }

    public function test_a_dean_may_not_read_the_institution_wide_aggregates(): void
    {
        $fixture = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $fixture['ccs']->id]);

        $this->actingAs($dean)->getJson('/api/vpaa/dashboard-insights')->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);

        $ccs = Departments::create(['department_name' => 'Computer Studies', 'department_code' => 'CCS']);
        $ced = Departments::create(['department_name' => 'Education', 'department_code' => 'CED']);

        // One room, owned by neither department — the shape that makes a
        // cross-department clash possible in the first place.
        $room = Rooms::create([
            'room_code' => 'SHARED101', 'room_type' => 'lecture', 'building' => 'Shared Hall',
            'status' => 'available', 'department_id' => null,
        ]);
        $onlineRoom = Rooms::create([
            'room_code' => 'ONLINE', 'room_type' => 'online',
            'status' => 'available', 'department_id' => null,
        ]);

        return [
            'semester' => $semester,
            'ccs' => $ccs,
            'ced' => $ced,
            'room' => $room,
            'onlineRoom' => $onlineRoom,
            'ccsSection' => $this->section($semester, $ccs, 'CCS-1A'),
            'cedSection' => $this->section($semester, $ced, 'CED-1A'),
            'ccsCourse' => $this->course($ccs, 'CCS101'),
            'cedCourse' => $this->course($ced, 'CED101'),
            'vpaa' => User::factory()->create(['role' => 'vpaa', 'department_id' => null]),
        ];
    }

    private function section(Semester $semester, Departments $department, string $name): Sections
    {
        return Sections::create([
            'section_name' => $name, 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
    }

    private function course(Departments $department, string $code): Course
    {
        return Course::create([
            'course_code' => $code, 'course_name' => $code.' Course',
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'major', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function meeting(
        array $fixture,
        string $sectionKey,
        string $departmentKey,
        string $day,
        string $start,
        string $end,
        string $status = 'approved',
        ?int $roomId = null,
    ): Schedule {
        return Schedule::create([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $fixture[$sectionKey]->id,
            'course_id' => $fixture[$departmentKey === 'ccs' ? 'ccsCourse' : 'cedCourse']->id,
            'faculty_id' => null,
            'room_id' => $roomId ?? $fixture['room']->id,
            'department_id' => $fixture[$departmentKey]->id,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => 'on-site',
            'status' => $status,
        ]);
    }
}
