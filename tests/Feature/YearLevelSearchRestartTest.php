<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Engine\CspSolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A section search that stops at its step or time limit has not shown the
 * section cannot be placed, only that this search got stuck. The generator
 * restarts it with a new seed and a doubled step budget rather than grinding
 * one search to the end of its time or changing what the user configured.
 */
class YearLevelSearchRestartTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stuck_search_restarts_with_a_new_seed_and_double_the_steps(): void
    {
        $fixture = $this->fixture(sectionCount: 1);
        // Stuck twice, then placed: what a poor early choice looks like.
        $solver = $this->fakeSolver($fixture, fn (array $call): bool => $call['index_for_section'] >= 2);
        $this->app->instance(CspSolver::class, $solver);

        $this->generate($fixture)->assertOk()
            ->assertJsonPath('applied_strategy', null)
            ->assertJsonPath('applied_adjustments', []);

        $calls = $solver->callsFor($fixture['sections'][0]);
        $this->assertSame([2000, 4000, 8000], array_column(array_slice($calls, 0, 3), 'max_iterations'));
        $seeds = array_column(array_slice($calls, 0, 3), 'seed');
        $this->assertCount(3, array_unique($seeds), 'Each restart searches with its own seed.');
        $this->assertSame([false, false, false], array_column(array_slice($calls, 0, 3), 'allow_room_tba_fallback'));
    }

    public function test_a_search_that_ends_before_its_limit_is_not_restarted(): void
    {
        $fixture = $this->fixture(sectionCount: 1);
        // The first search tries every candidate and finds nothing: another
        // seed would only repeat it, so the next call is the next attempt.
        $solver = $this->fakeSolver(
            $fixture,
            fn (array $call): bool => $call['index_for_section'] >= 1,
            limitReached: false,
        );
        $this->app->instance(CspSolver::class, $solver);

        $this->generate($fixture)->assertOk();

        $calls = $solver->callsFor($fixture['sections'][0]);
        $this->assertCount(2, $calls);
        $this->assertSame(7919, $calls[1]['seed'] - $calls[0]['seed'], 'The second call is attempt 1, not a restart.');
    }

    public function test_a_section_gives_up_sooner_while_the_previous_section_has_other_arrangements(): void
    {
        $fixture = $this->fixture(sectionCount: 2);
        [$first, $second] = $fixture['sections'];
        // The second section only fits the first section's last arrangement.
        $solver = $this->fakeSolver($fixture, function (array $call) use ($second): bool {
            return $call['section_id'] !== (int) $second->id || $call['arrangement'] === 3;
        });
        $this->app->instance(CspSolver::class, $solver);

        $this->generate($fixture)->assertOk()
            ->assertJsonPath('applied_adjustments', []);

        $byArrangement = collect($solver->callsFor($second))->groupBy('arrangement');
        $this->assertCount(3, $byArrangement, 'Every arrangement of the first section was tried.');
        foreach ([1, 2] as $arrangement) {
            foreach ($byArrangement[$arrangement] as $call) {
                $this->assertLessThanOrEqual(6.0, $call['timeout_seconds'], "Arrangement {$arrangement} still had alternatives.");
            }
        }
        $this->assertGreaterThan(6.0, $byArrangement[3]->first()['timeout_seconds'], 'The last arrangement keeps the full attempt.');
        $this->assertNotEmpty($solver->callsFor($first));
    }

    /**
     * @param  array{sections: list<Sections>, courses: list<Course>, semester: Semester, department: Departments, user: User}  $fixture
     */
    private function generate(array $fixture): \Illuminate\Testing\TestResponse
    {
        $courseIds = array_map(static fn (Course $course): int => (int) $course->id, $fixture['courses']);

        return $this->actingAs($fixture['user'])->postJson('/api/schedule-recommendations/year-level-preview', [
            'semester_id' => $fixture['semester']->id,
            'department_id' => $fixture['department']->id,
            'year_level' => 1,
            'section_configs' => array_map(static fn (Sections $section): array => [
                'section_id' => $section->id,
                'course_ids' => $courseIds,
                // Three Split Session courses make the section split-heavy, so
                // an attempt may run 24 seconds and the 6-second cap shows.
                'selected_gec_course_ids' => $courseIds,
            ], $fixture['sections']),
        ]);
    }

    private function fixture(int $sectionCount): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'gec_split_schedule_override_enabled' => true,
        ]);
        $program = \App\Models\Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        Rooms::create(['room_code' => 'IT 101', 'building' => 'IT Building', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);

        $sections = [];
        foreach (array_slice(['IT 1A', 'IT 1B'], 0, $sectionCount) as $name) {
            $sections[] = Sections::create(['section_name' => $name, 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active', 'curriculum_id' => $curriculum->id]);
        }

        $courses = [];
        foreach (['GEC 101', 'GEC 102', 'GEC 103'] as $code) {
            $course = Course::create([
                'course_code' => $code, 'course_name' => $code, 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
                'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
                'department_id' => null, 'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courses[] = $course;
        }

        return [
            'semester' => $semester,
            'department' => $department,
            'sections' => $sections,
            'courses' => $courses,
            'user' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
        ];
    }

    /**
     * A solver that records every call and answers from `$solves`. Each call
     * carries `index_for_section` (0-based count of earlier calls for that
     * section) and `arrangement` (1-based order in which the other sections'
     * placed schedules were first seen; 0 while nothing else is placed). A
     * call that does not solve reports its step budget used and, unless told
     * otherwise, its limit reached.
     */
    private function fakeSolver(array $fixture, callable $solves, bool $limitReached = true): CspSolver
    {
        return new class($fixture, $solves, $limitReached) extends CspSolver
        {
            /** @var list<array<string, mixed>> */
            private array $calls = [];

            /** @var array<string, int> */
            private array $arrangements = [];

            private int $lastIterations = 0;

            private bool $lastLimitReached = false;

            public function __construct(
                private readonly array $fixture,
                private $solves,
                private readonly bool $stuckLimitReached,
            ) {}

            public function callsFor(Sections $section): array
            {
                return array_values(array_filter($this->calls, static fn (array $call): bool => $call['section_id'] === (int) $section->id));
            }

            public function solveRankedFromSchema(array $input): array
            {
                $sectionId = (int) $input['section_id'];
                $placed = array_filter($input['tentative_schedules'] ?? [], static fn (array $row): bool => (int) $row['section_id'] !== $sectionId);
                $arrangement = 0;
                if ($placed !== []) {
                    $signature = json_encode(array_map(static fn (array $row): string => $row['day'].$row['start_time'], array_values($placed)));
                    $arrangement = $this->arrangements[$signature] ??= count($this->arrangements) + 1;
                }

                $call = [
                    'section_id' => $sectionId,
                    'index_for_section' => count($this->callsFor(Sections::query()->findOrFail($sectionId))),
                    'arrangement' => $arrangement,
                    'max_iterations' => (int) $input['max_iterations'],
                    'timeout_seconds' => (float) $input['timeout_seconds'],
                    'seed' => (int) $input['seed'],
                    'allow_room_tba_fallback' => (bool) $input['allow_room_tba_fallback'],
                ];
                $this->calls[] = $call;

                if (! ($this->solves)($call)) {
                    $this->lastIterations = $call['max_iterations'];
                    $this->lastLimitReached = $this->stuckLimitReached;

                    return [];
                }

                $this->lastIterations = 10;
                $this->lastLimitReached = false;
                $offset = array_search($sectionId, array_map(static fn (Sections $section): int => (int) $section->id, $this->fixture['sections']), true);

                // Three distinct arrangements, one day each, so the next
                // section can tell which one it is placed after.
                return array_map(fn (string $day): array => [
                    'rank' => 1,
                    'score' => 0,
                    'schedules' => array_map(fn (Course $course, int $index): array => [
                        'semester_id' => (int) $this->fixture['semester']->id,
                        'section_id' => $sectionId,
                        'course_id' => (int) $course->id,
                        'faculty_id' => null,
                        'room_id' => null,
                        'department_id' => (int) $this->fixture['department']->id,
                        'day' => $day,
                        'start_time' => sprintf('%02d:00:00', 7 + ($offset * 4) + $index),
                        'end_time' => sprintf('%02d:00:00', 8 + ($offset * 4) + $index),
                        'mode' => 'online',
                        'is_hybrid' => false,
                        'preferred_pattern' => null,
                        'status' => 'draft',
                    ], $this->fixture['courses'], array_keys($this->fixture['courses'])),
                ], ['Monday', 'Tuesday', 'Wednesday']);
            }

            public function iterationsUsed(): int
            {
                return $this->lastIterations;
            }

            public function searchLimitReached(): bool
            {
                return $this->lastLimitReached;
            }

            public function departmentRoomFairness(): array
            {
                return [];
            }

            public function generationForcedDaysByCourseId(): array
            {
                return [];
            }
        };
    }
}
