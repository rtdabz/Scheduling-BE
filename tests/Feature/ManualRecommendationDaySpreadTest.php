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
