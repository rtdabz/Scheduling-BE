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
use App\Services\Scheduling\Engine\Rules\InstructorAvailabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employment type supplies default availability (full-time Mon-Fri
 * 7:00 AM-8:30 PM; part-time Mon-Fri 5:00-8:30 PM and Saturday 7:00 AM-8:30 PM).
 * Added windows extend the default; classes outside the combined hours are
 * refused on every assignment route.
 */
class FacultyDefaultAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_by_employment_type(): void
    {
        $inside = fn (string $type, string $day, string $start, string $end): bool =>
            ! InstructorAvailabilityRule::outsideAvailability($type, [], $day, $start, $end);

        $this->assertTrue($inside('full-time', 'Monday', '07:00', '08:30'));
        $this->assertTrue($inside('full-time', 'Friday', '16:00', '19:00'));
        $this->assertTrue($inside('full-time', 'Monday', '19:00', '20:30'));
        $this->assertFalse($inside('full-time', 'Saturday', '08:00', '09:00'));
        $this->assertFalse($inside('full-time', 'Sunday', '08:00', '09:00'));

        $this->assertTrue($inside('part-time', 'Monday', '17:30', '20:30'));
        $this->assertTrue($inside('part-time', 'Saturday', '07:00', '08:30'));
        $this->assertFalse($inside('part-time', 'Monday', '08:00', '09:00'));
        $this->assertFalse($inside('part-time', 'Monday', '16:30', '17:30'));
        $this->assertFalse($inside('part-time', 'Sunday', '08:00', '09:00'));
    }

    public function test_an_added_window_extends_the_default_without_replacing_it(): void
    {
        $morning = [['day_index' => 0, 'start_time' => '07:00', 'end_time' => '11:00']];

        $this->assertFalse(InstructorAvailabilityRule::outsideAvailability('part-time', $morning, 'Monday', '08:00', '11:00'));
        $this->assertFalse(InstructorAvailabilityRule::outsideAvailability('part-time', $morning, 'Monday', '17:30', '20:30'));
        // A class spanning the 11:00-17:30 gap is still outside.
        $this->assertTrue(InstructorAvailabilityRule::outsideAvailability('part-time', $morning, 'Monday', '10:00', '12:00'));
        // A window adjacent to the default joins it.
        $afternoon = [['day_index' => 0, 'start_time' => '14:00', 'end_time' => '17:30']];
        $this->assertFalse(InstructorAvailabilityRule::outsideAvailability('part-time', $afternoon, 'Monday', '16:00', '18:00'));
    }

    public function test_a_part_timer_is_refused_a_morning_class_until_a_window_is_added(): void
    {
        $fixture = $this->fixture('part-time');
        $class = $this->schedule($fixture, ['start_time' => '08:00', 'end_time' => '11:00']);
        $url = "/api/instructor-assignments/{$class->id}";

        $this->actingAs($fixture['user'])
            ->patchJson($url, ['faculty_id' => $fixture['faculty']->id])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'part_time_faculty_availability');
        $this->assertDatabaseHas('schedules', ['id' => $class->id, 'faculty_id' => null]);

        $this->putJson("/api/faculties/{$fixture['faculty']->id}/availabilities", [
            'availabilities' => [['day_index' => 0, 'start_time' => '07:00', 'end_time' => '11:00']],
        ])->assertOk();

        $this->patchJson($url, ['faculty_id' => $fixture['faculty']->id])->assertOk();
        $this->assertDatabaseHas('schedules', ['id' => $class->id, 'faculty_id' => $fixture['faculty']->id]);
    }

    public function test_bulk_assignment_follows_the_same_availability(): void
    {
        $fixture = $this->fixture('full-time');
        $saturday = $this->schedule($fixture, ['day' => 'Saturday', 'start_time' => '08:00', 'end_time' => '11:00']);

        $this->actingAs($fixture['user'])
            ->patchJson('/api/schedules/batch-faculty', [
                'assignments' => [['schedule_ids' => [$saturday->id], 'faculty_id' => $fixture['faculty']->id]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('violations.0.rule', 'part_time_faculty_availability');

        $evening = $this->schedule($fixture, ['start_time' => '16:00', 'end_time' => '19:00']);
        $this->patchJson('/api/schedules/batch-faculty', [
            'assignments' => [['schedule_ids' => [$evening->id], 'faculty_id' => $fixture['faculty']->id]],
        ])->assertOk();
    }

    public function test_windows_may_not_overlap_the_default_or_each_other(): void
    {
        $fixture = $this->fixture('part-time');
        $url = "/api/faculties/{$fixture['faculty']->id}/availabilities";

        $this->actingAs($fixture['user'])
            ->putJson($url, ['availabilities' => [['day_index' => 0, 'start_time' => '16:00', 'end_time' => '18:00']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('availabilities.0.start_time');

        $this->putJson($url, ['availabilities' => [
            ['day_index' => 0, 'start_time' => '07:00', 'end_time' => '10:00'],
            ['day_index' => 0, 'start_time' => '09:00', 'end_time' => '11:00'],
        ]])->assertStatus(422)->assertJsonValidationErrors('availabilities.1.start_time');

        // Two separate windows on one day are fine.
        $this->putJson($url, ['availabilities' => [
            ['day_index' => 0, 'start_time' => '07:00', 'end_time' => '09:00'],
            ['day_index' => 0, 'start_time' => '13:00', 'end_time' => '15:00'],
        ]])->assertOk()->assertJsonCount(2, 'availabilities')->assertJsonPath('availability_mode', 'custom');
    }

    public function test_removing_a_window_that_covers_an_assigned_class_is_refused(): void
    {
        $fixture = $this->fixture('part-time');
        $url = "/api/faculties/{$fixture['faculty']->id}/availabilities";
        $this->actingAs($fixture['user'])
            ->putJson($url, ['availabilities' => [['day_index' => 0, 'start_time' => '07:00', 'end_time' => '11:00']]])
            ->assertOk();
        $this->schedule($fixture, ['start_time' => '08:00', 'end_time' => '11:00', 'faculty_id' => $fixture['faculty']->id]);

        $this->putJson($url, ['availabilities' => []])
            ->assertStatus(422)
            ->assertJsonCount(1, 'affected_schedules');
        $this->assertSame(1, $fixture['faculty']->availabilities()->count());
    }

    public function test_an_employment_change_that_strands_assigned_classes_is_refused(): void
    {
        $fixture = $this->fixture('full-time');
        $this->schedule($fixture, ['start_time' => '08:00', 'end_time' => '11:00', 'faculty_id' => $fixture['faculty']->id]);
        $vpaa = User::factory()->create(['role' => 'vpaa']);

        $this->actingAs($vpaa)
            ->putJson("/api/faculties/{$fixture['faculty']->id}", ['employment_type' => 'part-time'])
            ->assertStatus(422)
            ->assertJsonPath('affected_schedules.0.day', 'Monday');
        $this->assertSame('full-time', $fixture['faculty']->fresh()->employment_type);
    }

    public function test_the_availability_api_returns_the_default_week(): void
    {
        $fixture = $this->fixture('full-time');
        $url = "/api/faculties/{$fixture['faculty']->id}/availabilities";

        $this->actingAs($fixture['user'])->getJson($url)
            ->assertOk()
            ->assertJsonPath('availability_mode', 'standard')
            ->assertJsonCount(5, 'standard_availabilities')
            ->assertJsonPath('standard_availabilities.0.start_time', '07:00:00')
            ->assertJsonPath('standard_availabilities.0.end_time', '20:30:00');

        $fixture['faculty']->update(['employment_type' => 'part-time']);
        $this->getJson($url)->assertOk()->assertJsonCount(6, 'standard_availabilities');
    }

    /**
     * The UI matches added windows by day_index; without it in the payload every
     * added window is ignored and the page warns about classes inside them.
     */
    public function test_assignment_payload_carries_the_day_of_a_part_timers_added_window(): void
    {
        $this->assertAddedWindowDayIsSerialized('part-time', ['day_index' => 0, 'start_time' => '07:00', 'end_time' => '17:30']);
    }

    public function test_assignment_payload_carries_the_day_of_a_full_timers_added_window(): void
    {
        $this->assertAddedWindowDayIsSerialized('full-time', ['day_index' => 5, 'start_time' => '08:00', 'end_time' => '12:00']);
    }

    /** @param array{day_index: int, start_time: string, end_time: string} $window */
    private function assertAddedWindowDayIsSerialized(string $employmentType, array $window): void
    {
        $fixture = $this->fixture($employmentType);
        $this->schedule($fixture);

        $this->actingAs($fixture['user'])
            ->putJson("/api/faculties/{$fixture['faculty']->id}/availabilities", ['availabilities' => [$window]])
            ->assertOk();

        $faculty = collect($this->getJson('/api/instructor-assignments')->assertOk()->json('faculties'))
            ->firstWhere('id', $fixture['faculty']->id);

        $this->assertNotNull($faculty);
        $this->assertSame($window['day_index'], $faculty['availabilities'][0]['day_index'] ?? null);
    }

    /** @return array<string, mixed> */
    private function fixture(string $employmentType): array
    {
        $department = Departments::create(['department_name' => 'Hours Dept', 'department_code' => 'HRS']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'HRSP', 'name' => 'Hours Program']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        return [
            'department' => $department,
            'semester' => $semester,
            'room' => Rooms::create(['room_code' => 'HRS101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]),
            'course' => Course::create([
                'course_code' => 'HRS101',
                'course_name' => 'Hours Course',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'section' => Sections::create([
                'section_name' => 'HRS-1A',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'program_id' => $program->id,
                'semester_id' => $semester->id,
                'status' => 'active',
            ]),
            'faculty' => Faculty::create([
                'first_name' => 'Default',
                'last_name' => 'Hours',
                'employment_type' => $employmentType,
                'department_id' => $department->id,
                'status' => 'active',
            ]),
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function schedule(array $fixture, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'semester_id' => $fixture['semester']->id,
            'section_id' => $fixture['section']->id,
            'course_id' => $fixture['course']->id,
            'room_id' => $fixture['room']->id,
            'department_id' => $fixture['department']->id,
            'faculty_id' => null,
            'day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'faculty_assignment',
        ], $overrides));
    }
}
