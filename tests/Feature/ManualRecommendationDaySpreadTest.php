<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\CspSolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The placement dialog's "Find better options" asks the solver for a handful of
 * alternatives to one class. Those alternatives must reflect the week that is
 * actually free.
 *
 * CspSolver::candidateSearchDayTier steers a single meeting holding a real
 * lecture room to Friday and Saturday, so Monday-Thursday lecture capacity stays
 * open for other courses' MW and TTh patterns. The search only opens the
 * Monday-Thursday tier when Friday and Saturday cannot complete the timetable --
 * and for a lone course they always can, so every alternative came back on a
 * Friday while the rest of the week stood empty.
 */
class ManualRecommendationDaySpreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_alternatives_for_one_course_span_the_free_week_instead_of_stacking_on_friday(): void
    {
        [$section, $course] = $this->scaffold();

        $ranked = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [(int) $course->id],
            maxSolutions: 3,
            maxIterations: 50_000,
            timeoutSeconds: 8.0,
            seed: 20260920,
        );

        $this->assertCount(3, $ranked, 'The solver returned fewer alternatives than an empty week allows.');

        $days = [];
        foreach ($ranked as $solution) {
            foreach ($solution['schedules'] as $row) {
                $days[] = (string) $row['day'];
            }
        }
        $days = array_values(array_unique($days));

        $this->assertNotSame(
            ['Friday'],
            $days,
            'Every alternative landed on Friday although Monday to Thursday were free.',
        );
        $this->assertGreaterThan(
            1,
            count($days),
            'The alternatives all share one day: '.implode(', ', $days),
        );
    }

    /**
     * A placement that collided on Monday is looked for on Monday first: the
     * other free times that day, not the same time spread over the week.
     */
    public function test_alternatives_from_a_start_day_stay_on_that_day_while_it_has_room(): void
    {
        [$section, $course] = $this->scaffold();

        $ranked = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [(int) $course->id],
            maxSolutions: 3,
            maxIterations: 50_000,
            timeoutSeconds: 8.0,
            seed: 20260920,
            searchFromDay: 'Monday',
        );

        $this->assertCount(3, $ranked);
        foreach ($ranked as $solution) {
            $this->assertContains('Monday', array_column($solution['schedules'], 'day'));
        }
        $this->assertGreaterThan(
            1,
            count(array_unique(array_map(
                static fn (array $solution): string => implode(',', array_column($solution['schedules'], 'start_time')),
                $ranked,
            ))),
            'The Monday alternatives should offer different times, not one time in different rooms.',
        );
    }

    /**
     * When the start day has nothing left the search moves to the next day,
     * not to whichever day the week happens to begin with.
     */
    public function test_alternatives_move_to_the_next_day_when_the_start_day_is_full(): void
    {
        [$section, $course] = $this->scaffold();
        $blocker = Course::create([
            'course_code' => 'GE 102',
            'course_name' => 'Readings in History',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $section->department_id,
            'status' => 'active',
        ]);

        $ranked = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [(int) $course->id],
            maxSolutions: 3,
            maxIterations: 50_000,
            timeoutSeconds: 8.0,
            seed: 20260920,
            // The section is busy all of Wednesday, so a Wednesday start has to
            // continue on Thursday -- never Monday.
            tentativeSchedules: [[
                'semester_id' => $section->semester_id,
                'section_id' => $section->id,
                'course_id' => $blocker->id,
                'department_id' => $section->department_id,
                'day' => 'Wednesday',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'mode' => 'online',
                'room_id' => null,
            ]],
            searchFromDay: 'Wednesday',
        );

        $this->assertNotEmpty($ranked);
        foreach ($ranked as $solution) {
            $days = array_column($solution['schedules'], 'day');
            $this->assertNotContains('Wednesday', $days);
            $this->assertContains('Thursday', $days, 'Expected Thursday, got '.implode(', ', $days));
        }
    }

    /**
     * The weekend is the last place to look: a full Friday continues on
     * Monday, not on Saturday.
     */
    public function test_a_full_friday_continues_on_monday_before_the_weekend(): void
    {
        [$section, $course] = $this->scaffold();
        $blocker = Course::create([
            'course_code' => 'GE 102',
            'course_name' => 'Readings in History',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $section->department_id,
            'status' => 'active',
        ]);

        $ranked = app(CspSolver::class)->solveRanked(
            sectionId: (int) $section->id,
            courseIds: [(int) $course->id],
            maxSolutions: 3,
            maxIterations: 50_000,
            timeoutSeconds: 8.0,
            seed: 20260920,
            tentativeSchedules: [[
                'semester_id' => $section->semester_id,
                'section_id' => $section->id,
                'course_id' => $blocker->id,
                'department_id' => $section->department_id,
                'day' => 'Friday',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'mode' => 'online',
                'room_id' => null,
            ]],
            searchFromDay: 'Friday',
        );

        $this->assertNotEmpty($ranked);
        foreach ($ranked as $solution) {
            $days = array_column($solution['schedules'], 'day');
            $this->assertContains('Monday', $days, 'Expected Monday, got '.implode(', ', $days));
            $this->assertEmpty(array_intersect($days, ['Friday', 'Saturday', 'Sunday']));
        }
    }

    /**
     * A section with a single two-hour lecture course, three lecture rooms and
     * nothing else booked, so every weekday is available to it.
     *
     * @return array{0: Sections, 1: Course}
     */
    private function scaffold(): array
    {
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $department = Departments::create([
            'department_name' => 'College of Arts',
            'department_code' => 'CAS',
        ]);
        $section = Sections::create([
            'section_name' => 'AB 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $curriculum = Curriculum::create([
            'name' => 'Arts Curriculum',
            'department_id' => $department->id,
            'code' => 'AB-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        $course = Course::create([
            'course_code' => 'GE 101',
            'course_name' => 'Understanding the Self',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        foreach (['LEC-1', 'LEC-2', 'LEC-3'] as $code) {
            Rooms::create([
                'room_code' => $code,
                'building' => 'Main',
                'room_type' => 'lecture',
                'status' => 'available',
                'department_id' => $department->id,
                'max_concurrent_classes' => 1,
            ]);
        }

        return [$section, $course];
    }
}
