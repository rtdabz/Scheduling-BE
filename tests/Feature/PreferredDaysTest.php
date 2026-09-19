<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Generation\CourseSetupOverrides;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\YearLevel\YearLevelFeasibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Step 1's Preferred Days: the generator may place a meeting only on the
 * chosen days, for every class shape -- a single meeting, a Split Session,
 * Hybrid Split and Integrated Hybrid.
 */
class PreferredDaysTest extends TestCase
{
    use RefreshDatabase;

    private Sections $section;

    private Departments $department;

    protected function setUp(): void
    {
        parent::setUp();

        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $this->department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
        ]);
        $this->section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $this->department->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $this->section->setRelation('department', $this->department);

        foreach (['LEC 1' => 'lecture', 'LAB 1' => 'laboratory'] as $code => $type) {
            Rooms::create([
                'room_code' => $code,
                'room_type' => $type,
                'status' => 'available',
                'department_id' => $this->department->id,
            ]);
        }
    }

    public function test_a_regular_class_meets_only_on_a_preferred_day(): void
    {
        $course = $this->course('IT 101', lecture: 3, laboratory: 0, category: 'major');
        $days = ['Monday', 'Tuesday', 'Wednesday'];

        foreach ([11, 222, 3333, 44444] as $seed) {
            $this->assertDaysWithin($days, $this->generate($course, $days, seed: $seed));
        }
    }

    public function test_a_split_session_uses_only_preferred_days(): void
    {
        $course = $this->course('GEC 1', lecture: 3, laboratory: 0, category: 'minor');
        $days = ['Tuesday', 'Thursday'];

        $rows = $this->generate($course, $days, shape: 'split');

        $this->assertCount(2, $rows, 'two Preferred Days still hold the whole Split Session');
        $this->assertDaysWithin($days, $rows);
    }

    public function test_a_split_session_pairs_the_chosen_days_when_neither_mw_nor_tth_fits(): void
    {
        $course = $this->course('GEC 3', lecture: 3, laboratory: 0, category: 'minor');
        $days = ['Monday', 'Tuesday'];

        $rows = $this->generate($course, $days, shape: 'split');

        $this->assertCount(2, $rows, 'back-to-back Preferred Days still hold a Split Session');
        $this->assertEqualsCanonicalizing($days, array_column($rows, 'day'));
    }

    public function test_hybrid_split_uses_only_preferred_days(): void
    {
        $course = $this->course('GEC 2', lecture: 3, laboratory: 0, category: 'minor');
        $days = ['Monday', 'Friday'];

        $rows = $this->generate($course, $days, shape: 'hybrid_split');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['online', 'on-site'], array_column($rows, 'mode'));
        $this->assertDaysWithin($days, $rows);
    }

    public function test_integrated_hybrid_uses_only_preferred_days(): void
    {
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, category: 'major');
        $days = ['Wednesday', 'Saturday'];

        $rows = $this->generate($course, $days, shape: 'integrated_hybrid');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['lecture', 'laboratory'], array_column($rows, 'meeting_type'));
        $this->assertDaysWithin($days, $rows);
    }

    public function test_one_preferred_day_cannot_hold_a_hybrid_and_the_error_says_so(): void
    {
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, category: 'major');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be scheduled on the Preferred Days (Monday). Its meetings need two different days');

        $this->generate($course, ['Monday'], shape: 'integrated_hybrid');
    }

    public function test_the_pre_check_names_a_hybrid_that_one_preferred_day_cannot_hold(): void
    {
        $course = $this->course('IT 103', lecture: 2, laboratory: 1, category: 'major');
        $config = [
            'course_ids' => [(int) $course->id],
            'selected_split_session_course_ids' => [(int) $course->id],
            'allowed_days' => ['Monday'],
        ];

        $blocking = app(YearLevelFeasibilityService::class)->check([$this->section], [(int) $this->section->id => $config]);

        $this->assertContains('preferred_days_too_few_for_hybrid', array_column($blocking, 'code'));
    }

    public function test_a_required_day_outside_the_preferred_days_is_refused_up_front(): void
    {
        $course = $this->course('IT 101', lecture: 3, laboratory: 0, category: 'major');
        DB::table('department_forced_course_days')->insert([
            'department_id' => $this->department->id,
            'course_id' => $course->id,
            'day' => 'Saturday',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        CourseSetupOverrides::assertRequiredDaysAllowed($this->section, [(int) $course->id], ['Monday', 'Tuesday']);
    }

    public function test_preferred_days_are_normalised(): void
    {
        $this->assertSame(['Monday', 'Wednesday'], SchedulingPolicy::normalizeAllowedDays(['wednesday', 'Monday', 'Funday']));
        $this->assertNull(SchedulingPolicy::normalizeAllowedDays([]), 'none chosen means any day');
        $this->assertNull(SchedulingPolicy::normalizeAllowedDays(SchedulingPolicy::DAYS), 'all seven means any day');
        $this->assertNull(SchedulingPolicy::normalizeAllowedDays(null));
    }

    private function course(string $code, int $lecture, int $laboratory, string $category): Course
    {
        return Course::create([
            'course_code' => $code,
            'course_name' => $code.' course',
            'lecture_hours' => $lecture,
            'lab_hours' => $laboratory,
            'units' => 3,
            'course_category' => $category,
            'room_type_required' => $laboratory > 0 ? 'laboratory' : 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<string>  $days
     * @return list<array<string, mixed>>
     */
    private function generate(Course $course, array $days, string $shape = 'regular', int $seed = 4321): array
    {
        $id = (int) $course->id;
        $options = ['selected_split_session_course_ids' => $shape === 'integrated_hybrid' ? [$id] : []];

        $solutions = app(CspSolver::class)->solveRanked(
            sectionId: (int) $this->section->id,
            courseIds: [$id],
            isHybrid: $shape === 'integrated_hybrid',
            selectedLectureLabCourseIds: $shape === 'integrated_hybrid' ? [$id] : [],
            balancedSplitCourseIds: in_array($shape, ['split', 'hybrid_split'], true) ? [$id] : [],
            hybridSplitCourseIds: $shape === 'hybrid_split' ? [$id] : [],
            requirementsByCourseId: app(ScheduleRequirementBuilderResolver::class)->build($this->section, [$id], $options),
            allowedDays: $days,
            maxSolutions: 1,
            seed: $seed,
        );

        $this->assertNotEmpty($solutions, 'the generator produced no solution');

        return $solutions[0]['schedules'];
    }

    /**
     * @param  list<string>  $days
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertDaysWithin(array $days, array $rows): void
    {
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertContains($row['day'], $days, 'a meeting was placed on a day that was not chosen');
        }
    }
}
