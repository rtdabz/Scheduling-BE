<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual Scheduling of a Consecutive Days class: the run is saved as linked
 * meetings, judged as a run, moved as a run, and offered as whole runs.
 */
class ConsecutiveDaysManualSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_is_saved_as_one_linked_group(): void
    {
        $context = $this->scaffold();

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => $this->runOperations($context, ['Thursday', 'Friday', 'Saturday']),
        ]);

        $response->assertOk();
        $rows = Schedule::query()->where('course_id', $context['course']->id)->get();
        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->pluck('split_group_id')->unique());
        $this->assertEqualsCanonicalizing(['Thursday', 'Friday', 'Saturday'], $rows->pluck('day')->all());
        $this->assertSame(['consecutive:3'], $rows->pluck('preferred_pattern')->unique()->values()->all());
    }

    public function test_a_run_with_a_gap_is_refused(): void
    {
        $context = $this->scaffold();

        $response = $this->actingAs($context['user'])->postJson('/api/schedules/batch', [
            'operations' => $this->runOperations($context, ['Monday', 'Wednesday', 'Thursday']),
        ]);

        $response->assertStatus(422);
        $this->assertContains('consecutive_days', array_column($response->json('violations') ?? [], 'rule'));
        $this->assertSame(0, Schedule::query()->where('course_id', $context['course']->id)->count());
    }

    public function test_each_day_of_a_run_may_take_the_full_class_length(): void
    {
        $context = $this->scaffold();
        // Four hours a day: three days (12 h) are more than the course's own
        // week (9 h, its three laboratory units), but within a 3-day run's.
        $this->savedRun($context, ['Thursday', 'Friday', 'Saturday'], '07:00', '11:00');
        Schedule::query()->where('day', 'Saturday')->delete();
        $saturday = [
            ...$this->row($context),
            'day' => 'Saturday',
            'start_time' => '07:00',
            'end_time' => '11:00',
        ];
        $durationRules = static fn (array $violations): array => array_values(array_filter(
            array_column($violations, 'rule'),
            static fn (string $rule): bool => $rule === 'class_duration',
        ));

        $this->assertSame([], $durationRules(app(\App\Services\Scheduling\Engine\RuleEngine::class)->validate([
            ...$saturday,
            'preferred_pattern' => 'consecutive:3',
        ])), 'a 3-day run holds three classes\' worth of time');
        $this->assertSame(['class_duration'], $durationRules(app(\App\Services\Scheduling\Engine\RuleEngine::class)->validate($saturday)), 'an extra plain meeting is still refused');
    }

    public function test_moving_one_day_shifts_the_whole_run(): void
    {
        $context = $this->scaffold();
        [$thursday, $friday, $saturday] = $this->savedRun($context, ['Thursday', 'Friday', 'Saturday']);

        $response = $this->actingAs($context['user'])->putJson("/api/schedules/{$thursday->id}", [
            'day' => 'Wednesday',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('moved_partners'));
        $this->assertSame('Thursday', $friday->refresh()->day);
        $this->assertSame('Friday', $saturday->refresh()->day);
        foreach ([$thursday, $friday, $saturday] as $row) {
            $this->assertSame('09:00', substr((string) $row->refresh()->start_time, 0, 5));
            $this->assertSame('10:00', substr((string) $row->end_time, 0, 5));
        }
    }

    public function test_a_shift_past_the_end_of_the_week_is_refused(): void
    {
        $context = $this->scaffold(sundayClasses: true);
        [$friday, $saturday, $sunday] = $this->savedRun($context, ['Friday', 'Saturday', 'Sunday']);

        $response = $this->actingAs($context['user'])->putJson("/api/schedules/{$friday->id}", [
            'day' => 'Saturday',
            'start_time' => '07:00',
            'end_time' => '08:00',
        ]);

        $response->assertStatus(422);
        $this->assertContains('consecutive_days', array_column($response->json('violations') ?? [], 'rule'));
        $this->assertSame('Friday', $friday->refresh()->day);
        $this->assertSame('Sunday', $sunday->refresh()->day);
    }

    public function test_available_slots_offer_only_whole_runs(): void
    {
        $context = $this->scaffold();
        // Another section holds the laboratory all Friday.
        Schedule::create([
            ...$this->row($context),
            'section_id' => Sections::create([
                'section_name' => 'NUR 2A',
                'year_level' => '2',
                'semester' => '1st',
                'department_id' => $context['department']->id,
                'program_id' => $context['section']->program_id,
                'semester_id' => $context['semester']->id,
                'status' => 'active',
            ])->id,
            'course_id' => Course::create([...$this->courseAttributes($context['department']), 'course_code' => 'NUR 299'])->id,
            'day' => 'Friday',
            'start_time' => '07:00',
            'end_time' => '21:00',
        ]);

        $response = $this->actingAs($context['user'])->postJson('/api/schedule-recommendations/available-slots', [
            'section_id' => $context['section']->id,
            'course_id' => $context['course']->id,
            'duration_slots' => 2,
            'modes' => ['on-site'],
            'consecutive_days' => 3,
        ]);

        $response->assertOk();
        $slots = $response->json('slots');
        $this->assertNotEmpty($slots);
        $runs = array_unique(array_map(static fn (array $slot): string => implode(',', $slot['run_days']), $slots));
        sort($runs);
        // Every run through Friday is gone; Monday-Wednesday and Tuesday-Thursday remain.
        $this->assertSame(['Monday,Tuesday,Wednesday', 'Tuesday,Wednesday,Thursday'], $runs);
        foreach ($slots as $slot) {
            $this->assertSame($slot['run_days'][0], $slot['day'], 'a run is listed on its first day');
        }
    }

    /** @return array{user: User, department: Departments, semester: Semester, section: Sections, course: Course, room: Rooms} */
    private function scaffold(bool $sundayClasses = false): array
    {
        $department = Departments::create([
            'department_name' => 'College of Nursing',
            'department_code' => 'NUR',
            'scheduling_profile' => 'laboratory_enabled',
            'sunday_classes_enabled' => $sundayClasses,
        ]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSN', 'name' => 'Nursing']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create([
            'room_code' => 'SKILLS-1',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
        ]);
        $section = Sections::create([
            'section_name' => 'NUR 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        return [
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
            'department' => $department,
            'semester' => $semester,
            'section' => $section,
            'course' => Course::create([...$this->courseAttributes($department), 'course_code' => 'CLIN 101']),
            'room' => $room,
        ];
    }

    /** @return array<string, mixed> 3 units: up to three hours on each day of a run */
    private function courseAttributes(Departments $department): array
    {
        return [
            'course_name' => 'Clinical Duty',
            'lecture_hours' => 0,
            'lab_hours' => 3,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ];
    }

    /** @return array<string, mixed> */
    private function row(array $context): array
    {
        return [
            'semester_id' => $context['semester']->id,
            'section_id' => $context['section']->id,
            'course_id' => $context['course']->id,
            'department_id' => $context['department']->id,
            'room_id' => $context['room']->id,
            'mode' => 'on-site',
            'status' => 'draft',
        ];
    }

    /**
     * @param  list<string>  $days
     * @return list<array<string, mixed>>
     */
    private function runOperations(array $context, array $days, string $start = '07:00', string $end = '08:00'): array
    {
        return array_map(fn (string $day, int $index): array => [
            ...$this->row($context),
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'preferred_pattern' => 'consecutive:'.count($days),
            'split_group_id' => 'clinical-run',
            'meeting_index' => $index + 1,
        ], $days, array_keys($days));
    }

    /**
     * @param  list<string>  $days
     * @return list<Schedule>
     */
    private function savedRun(array $context, array $days, string $start = '07:00', string $end = '08:00'): array
    {
        return array_map(fn (array $operation): Schedule => Schedule::create($operation), $this->runOperations($context, $days, $start, $end));
    }
}
