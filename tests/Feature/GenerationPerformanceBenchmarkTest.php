<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\Generation\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\YearLevel\YearLevelScheduleGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wall-clock harness for year-level generation, used to verify the performance
 * work. Not a pass/fail guard: the assertion only checks that a timetable was
 * produced, and the timing is printed for comparison across runs.
 *
 *   php artisan test --filter=GenerationPerformanceBenchmark
 */
class GenerationPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_year_level_preview_timing_breakdown(): void
    {
        $coursesPerSection = (int) (getenv('BENCH_COURSES') ?: 8);
        $sectionCount = (int) (getenv('BENCH_SECTIONS') ?: 6);
        $preferredPeriod = getenv('BENCH_PERIOD') ?: 'none';
        $splitCourses = (int) (getenv('BENCH_SPLITS') ?: 2);
        $lectureRooms = (int) (getenv('BENCH_LECTURE_ROOMS') ?: 8);
        $laboratoryRooms = (int) (getenv('BENCH_LAB_ROOMS') ?: 5);
        $roomCount = $lectureRooms + $laboratoryRooms;

        [$sections, $configs] = $this->buildScenario(
            sectionCount: $sectionCount,
            coursesPerSection: $coursesPerSection,
            lectureRooms: $lectureRooms,
            laboratoryRooms: $laboratoryRooms,
        );

        $queryCount = 0;
        $queryMs = 0.0;
        DB::listen(function ($query) use (&$queryCount, &$queryMs): void {
            $queryCount++;
            $queryMs += $query->time;
        });

        $startedAt = microtime(true);
        $result = app(YearLevelScheduleGenerationService::class)->preview($sections, $configs);
        $elapsed = microtime(true) - $startedAt;

        $metrics = $result['generation_metrics'] ?? [];

        fwrite(STDERR, sprintf(
            "\n=== YEAR-LEVEL PREVIEW BENCHMARK ===\n".
            "scenario           %d sections x %d courses, %d rooms\n".
            "preferred period   %8s\n".
            "split courses      %8d\n".
            "TOTAL WALL TIME    %8.2f s\n".
            "solver attempts    %8d\n".
            "iterations         %8d\n".
            "candidates built   %8d\n".
            "candidates pruned  %8d\n".
            "search limit hit   %8s\n".
            "database           %8d queries, %.2f s\n".
            "rows produced      %8d\n".
            "====================================\n",
            count($sections),
            $coursesPerSection,
            $roomCount,
            $preferredPeriod,
            $splitCourses,
            $elapsed,
            (int) ($metrics['solver_attempts'] ?? 0),
            (int) ($metrics['iterations'] ?? 0),
            (int) ($metrics['candidate_count_before'] ?? 0),
            (int) ($metrics['candidate_count_before'] ?? 0) - (int) ($metrics['candidate_count_after'] ?? 0),
            ($metrics['search_limit_reached'] ?? false) ? 'yes' : 'no',
            $queryCount,
            $queryMs / 1000,
            count($result['schedules'] ?? []),
        ));

        $this->assertNotEmpty($result['schedules'] ?? []);
    }

    /** @return array{0: list<Sections>, 1: array<int, array<string, mixed>>} */
    private function buildScenario(
        int $sectionCount,
        int $coursesPerSection,
        int $lectureRooms,
        int $laboratoryRooms,
    ): array {
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
            'lecture_lab_schedule_override_enabled' => true,
        ]);

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);

        foreach ([['LEC', 'lecture', $lectureRooms], ['LAB', 'laboratory', $laboratoryRooms]] as [$prefix, $type, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                Rooms::create([
                    'room_code' => "{$prefix}-{$i}",
                    'building' => 'Main',
                    'room_type' => $type,
                    'status' => 'available',
                    'department_id' => $department->id,
                    'max_concurrent_classes' => 1,
                ]);
            }
        }

        // A realistic mix: two laboratory courses scheduled as lecture/lab
        // splits, and the rest single-meeting lectures.
        $courses = [];
        $splitIds = [];
        $splitCourseCount = (int) (getenv('BENCH_SPLITS') ?: 2);
        for ($i = 1; $i <= $coursesPerSection; $i++) {
            $isLab = $i <= $splitCourseCount;
            $course = Course::create([
                'course_code' => "IT 1{$i}0",
                'course_name' => "Course {$i}",
                'lecture_hours' => $isLab ? 2 : 3,
                'lab_hours' => $isLab ? 1 : 0,
                'units' => 3,
                'course_category' => 'major',
                'room_type_required' => $isLab ? 'laboratory' : 'lecture',
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courses[] = $course;
            if ($isLab) {
                $splitIds[] = (int) $course->id;
            }
        }

        $courseIds = array_map(static fn (Course $course): int => (int) $course->id, $courses);

        $sections = [];
        $configs = [];
        for ($s = 1; $s <= $sectionCount; $s++) {
            $section = Sections::create([
                'section_name' => "IT 1{$s}",
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => $department->id,
                'term_id' => $term->id,
                'status' => 'active',
            ]);
            $sections[] = $section;

            $config = [
                'course_ids' => $courseIds,
                'mode' => 'on-site',
                'is_hybrid' => false,
                'selected_split_session_course_ids' => $splitIds,
                'balanced_split_course_ids' => [],
                'preferred_patterns' => [],
                'delivery_modes_by_course_id' => [],
                // BENCH_PERIOD restricts every section to one teaching window,
                // which is the expensive shape: the domain shrinks to about a
                // third of the day, so the search backtracks far more.
                'preferred_period' => getenv('BENCH_PERIOD') ?: null,
                'seed' => 1234 + $s,
            ];
            $config['requirements_by_course_id'] = app(ScheduleRequirementBuilderResolver::class)
                ->build($section, $courseIds, $config);
            $config['department_profile'] = 'laboratory_enabled';
            $configs[(int) $section->id] = $config;
        }

        return [$sections, $configs];
    }
}
