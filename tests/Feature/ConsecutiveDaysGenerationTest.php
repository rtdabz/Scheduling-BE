<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Consecutive Days: a Regular class a department sets to meet on N back-to-back
 * days (a clinical duty on Thursday, Friday and Saturday) is generated as one
 * linked run, at one time in one room, for its full length every day, for the
 * sections the rule names.
 */
class ConsecutiveDaysGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_run_meets_on_the_ticked_days(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context);
        $this->rule($context, $clinical, days: 3, startDay: 'Thursday');

        $rows = $this->generate($context, [$clinical]);

        $this->assertCount(3, $rows);
        $this->assertSame(['Thursday', 'Friday', 'Saturday'], $this->days($rows));
        $this->assertCount(1, array_unique(array_column($rows, 'start_time')), 'every day keeps one start time');
        $this->assertCount(1, array_unique(array_column($rows, 'room_id')), 'the generator keeps one room');
        $this->assertCount(1, array_unique(array_column($rows, 'split_group_id')));
        $this->assertNotNull($rows[0]['split_group_id']);
        foreach ($rows as $row) {
            $this->assertSame('consecutive:3', $row['preferred_pattern']);
            $this->assertNull($row['meeting_type'] ?? null, 'a run is not an Integrated session');
            $this->assertSame(180, SchedulingPolicy::timeToMinutes($row['end_time']) - SchedulingPolicy::timeToMinutes($row['start_time']), 'a 3-unit class meets 3 hours every day');
        }
        $this->assertSame([], app(RuleEngine::class)->validateConfiguredMeetingGroups($rows));
    }

    public function test_the_ticked_days_are_kept_even_when_their_room_is_taken(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context);
        $this->rule($context, $clinical, days: 3, startDay: 'Thursday');
        $this->bookLaboratoryAllDay($context, 'Friday');

        $rows = $this->generate($context, [$clinical]);

        // The only laboratory is taken on Friday, so the run waits on a room
        // (Room TBA) rather than moving off the days the user ticked.
        $this->assertSame(['Thursday', 'Friday', 'Saturday'], $this->days($rows));
        $this->assertSame([null], array_values(array_unique(array_column($rows, 'room_id'))));
    }

    public function test_ticked_days_outside_the_preferred_days_are_named(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context);
        $this->rule($context, $clinical, days: 3, startDay: 'Monday');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NUR 1A / CLIN 101 is set to meet Monday, Tuesday, Wednesday, but the Preferred Days (Monday, Tuesday, Thursday, Friday, Saturday) leave out some of those days.');

        $this->generate($context, [$clinical], allowedDays: ['Monday', 'Tuesday', 'Thursday', 'Friday', 'Saturday']);
    }

    public function test_without_ticked_days_a_weekday_run_comes_before_one_with_saturday(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context);
        $this->rule($context, $clinical, days: 2);

        $days = $this->days($this->generate($context, [$clinical]));

        $this->assertTrue(SchedulingPolicy::isConsecutiveDaySet($days));
        $this->assertCount(2, $days);
        $this->assertNotContains('Saturday', $days);
    }

    public function test_a_section_rule_leaves_the_other_sections_alone(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context);
        $otherSection = Sections::create([
            'section_name' => 'NUR 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'semester_id' => $context['semester']->id,
            'status' => 'active',
        ]);
        $this->rule($context, $clinical, days: 3, sectionId: (int) $otherSection->id);

        $rows = $this->generate($context, [$clinical]);

        $this->assertCount(1, $rows, 'NUR 1A has no rule, so it keeps a single meeting');
        $this->assertNull($rows[0]['preferred_pattern']);
    }

    public function test_an_eight_unit_class_meets_eight_hours_straight_on_every_day(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context, units: 8);
        $this->rule($context, $clinical, days: 3, startDay: 'Thursday');

        $rows = $this->generate($context, [$clinical]);

        $this->assertSame(['Thursday', 'Friday', 'Saturday'], $this->days($rows));
        foreach ($rows as $row) {
            $this->assertSame(480, SchedulingPolicy::timeToMinutes($row['end_time']) - SchedulingPolicy::timeToMinutes($row['start_time']));
        }
    }

    public function test_a_required_day_on_a_run_is_refused_with_its_fix(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context);
        $this->rule($context, $clinical, days: 3);
        DB::table('department_forced_course_days')->insert([
            'department_id' => $context['department']->id,
            'course_id' => $clinical->id,
            'day' => 'Monday',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NUR 1A / CLIN 101 has a Required Day of Monday and is also set to meet on 3 consecutive days.');

        $this->generate($context, [$clinical]);
    }

    public function test_preferred_days_without_a_long_enough_run_are_named(): void
    {
        $context = $this->scaffold();
        $clinical = $this->clinicalCourse($context);
        $this->rule($context, $clinical, days: 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs 3 consecutive days, but the Preferred Days (Monday, Wednesday, Friday) have no 3 back-to-back days.');

        $this->generate($context, [$clinical], allowedDays: ['Monday', 'Wednesday', 'Friday']);
    }

    /** @return array{semester: Semester, department: Departments, section: Sections, curriculum: Curriculum, room: Rooms} */
    private function scaffold(): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'College of Nursing',
            'department_code' => 'NUR',
            'scheduling_profile' => 'laboratory_enabled',
        ]);
        $section = Sections::create([
            'section_name' => 'NUR 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $room = Rooms::create([
            'room_code' => 'SKILLS-1',
            'building' => 'Main',
            'room_type' => 'laboratory',
            'status' => 'available',
            'department_id' => $department->id,
            'max_concurrent_classes' => 1,
        ]);

        return [
            'semester' => $semester,
            'department' => $department,
            'section' => $section,
            'room' => $room,
            'curriculum' => Curriculum::create([
                'name' => 'Nursing Curriculum',
                'department_id' => $department->id,
                'code' => 'NUR-2026',
                'effective_school_year' => '2026-2027',
                'status' => 'active',
            ]),
        ];
    }

    private function clinicalCourse(array $context, int $units = 3, string $code = 'CLIN 101'): Course
    {
        $course = Course::create([
            'course_code' => $code,
            'course_name' => 'Clinical Duty',
            'course_category' => 'major',
            'lecture_hours' => 0,
            'lab_hours' => $units,
            'units' => $units,
            'room_type_required' => 'laboratory',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'status' => 'active',
        ]);
        $context['curriculum']->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        return $course;
    }

    private function rule(array $context, Course $course, int $days, ?string $startDay = null, ?int $sectionId = null): void
    {
        DB::table('course_consecutive_day_rules')->insert([
            'department_id' => $context['department']->id,
            'course_id' => $course->id,
            'section_id' => $sectionId,
            'day_count' => $days,
            'preferred_start_day' => $startDay,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bookLaboratoryAllDay(array $context, string $day): void
    {
        $blocker = Sections::create([
            'section_name' => 'NUR 2A',
            'year_level' => '2',
            'semester' => '1st',
            'department_id' => $context['department']->id,
            'semester_id' => $context['semester']->id,
            'status' => 'active',
        ]);
        $filler = $this->clinicalCourse($context, code: 'NUR 299');

        Schedule::create([
            'semester_id' => $context['semester']->id,
            'section_id' => $blocker->id,
            'course_id' => $filler->id,
            'room_id' => $context['room']->id,
            'department_id' => $context['department']->id,
            'day' => $day,
            'start_time' => SchedulingPolicy::openingTime(),
            'end_time' => SchedulingPolicy::closingTime(),
            'mode' => 'on-site',
            'status' => 'finalized',
        ]);
    }

    /**
     * @param  list<Course>  $courses
     * @param  list<string>|null  $allowedDays
     * @return list<array<string, mixed>>
     */
    private function generate(array $context, array $courses, ?array $allowedDays = null): array
    {
        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: array_map(static fn (Course $course): int => (int) $course->id, $courses),
            maxSolutions: 1,
            seed: 1234,
            allowedDays: $allowedDays,
        );

        $this->assertNotEmpty($solutions, 'Generation produced no solution.');

        return $solutions[0]['schedules'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function days(array $rows): array
    {
        $days = array_column($rows, 'day');
        usort($days, static fn (string $left, string $right): int => SchedulingPolicy::dayIndex($left) <=> SchedulingPolicy::dayIndex($right));

        return $days;
    }
}
