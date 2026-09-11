<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\Engine\CspSolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The solver caches each course's built candidate set across the many attempts
 * one generation run makes. These tests pin the two properties that makes safe:
 * a repeated solve must reuse the cache without changing the answer, and any
 * input that changes the candidate set must miss the cache.
 */
class DomainCacheEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_solve_in_one_context_returns_the_same_schedule(): void
    {
        $context = $this->scaffold();
        $solver = app(CspSolver::class);
        $solver->beginGenerationContext();

        $first = $this->solve($solver, $context);
        // Second solve hits the warm domain cache for every course.
        $second = $this->solve($solver, $context);

        $this->assertSame(
            $this->fingerprint($first),
            $this->fingerprint($second),
            'A cache hit changed the generated schedule.',
        );
    }

    public function test_a_cold_context_produces_the_same_schedule_as_a_warm_one(): void
    {
        $context = $this->scaffold();

        $warmSolver = app(CspSolver::class);
        $warmSolver->beginGenerationContext();
        $this->solve($warmSolver, $context);
        $warm = $this->solve($warmSolver, $context);

        // A brand new context builds every domain from scratch.
        $coldSolver = app(CspSolver::class);
        $coldSolver->beginGenerationContext();
        $cold = $this->solve($coldSolver, $context);

        $this->assertSame(
            $this->fingerprint($cold),
            $this->fingerprint($warm),
            'The cached domain diverged from a freshly built one.',
        );
    }

    public function test_changing_delivery_mode_is_not_served_from_cache(): void
    {
        $context = $this->scaffold();
        $solver = app(CspSolver::class);
        $solver->beginGenerationContext();

        $onSite = $this->solve($solver, $context, ['mode' => 'on-site']);
        $online = $this->solve($solver, $context, ['mode' => 'online']);

        $this->assertNotSame(
            $this->fingerprint($onSite),
            $this->fingerprint($online),
            'A configuration change was wrongly served from the domain cache.',
        );
        $this->assertSame(
            ['online'],
            array_values(array_unique(array_column($online, 'mode'))),
            'The online solve did not actually produce online rows.',
        );
    }

    /**
     * Two sections share one cached candidate set, so the per-section seeded
     * shuffle and the conflict checks are what keep them apart. If sharing ever
     * leaked section identity into the domain, both sections would chase the
     * same rooms at the same hours.
     */
    public function test_two_sections_sharing_a_cached_domain_do_not_collide(): void
    {
        $context = $this->scaffold();
        $second = Sections::create([
            'section_name' => 'IT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $context['section']->department_id,
            'term_id' => $context['section']->term_id,
            'status' => 'active',
        ]);

        $solver = app(CspSolver::class);
        $solver->beginGenerationContext();

        $first = $this->solve($solver, $context);
        $secondRows = $this->solve(
            $solver,
            ['section' => $second, 'course_ids' => $context['course_ids']],
            ['tentative' => $first],
        );

        $occupied = [];
        foreach ($first as $row) {
            $occupied[] = $row['day'].'|'.$row['start_time'].'|'.($row['room_id'] ?? 'null');
        }
        foreach ($secondRows as $row) {
            $slot = $row['day'].'|'.$row['start_time'].'|'.($row['room_id'] ?? 'null');
            $this->assertNotContains(
                $slot,
                $occupied,
                "Both sections claimed {$slot} from the shared candidate set.",
            );
        }
    }

    /** @param array<string, mixed> $overrides */
    private function solve(CspSolver $solver, array $context, array $overrides = []): array
    {
        $solutions = $solver->solveRanked(
            sectionId: (int) $context['section']->id,
            courseIds: $context['course_ids'],
            maxSolutions: 1,
            deliveryMode: (string) ($overrides['mode'] ?? 'on-site'),
            seed: 4242,
            tentativeSchedules: $overrides['tentative'] ?? [],
        );

        $this->assertNotEmpty($solutions, 'Generation produced no solution.');

        return $solutions[0]['schedules'];
    }

    /** @param list<array<string, mixed>> $rows */
    private function fingerprint(array $rows): string
    {
        $parts = array_map(
            static fn (array $row): string => implode('|', [
                $row['course_id'] ?? '',
                $row['day'] ?? '',
                $row['start_time'] ?? '',
                $row['end_time'] ?? '',
                $row['room_id'] ?? 'null',
                $row['mode'] ?? '',
            ]),
            $rows,
        );
        sort($parts);

        return implode("\n", $parts);
    }

    /** @return array{section: Sections, course_ids: list<int>} */
    private function scaffold(): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        $section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active',
        ]);

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum', 'department_id' => $department->id,
            'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);

        foreach (['LEC-1', 'LEC-2', 'LEC-3'] as $code) {
            Rooms::create([
                'room_code' => $code, 'building' => 'Main', 'room_type' => 'lecture',
                'status' => 'available', 'department_id' => $department->id,
                'max_concurrent_classes' => 1,
            ]);
        }

        $courseIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $course = Course::create([
                'course_code' => "IT 10{$i}",
                'course_name' => "Course {$i}",
                'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
                'course_category' => 'major', 'room_type_required' => 'lecture',
                'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courseIds[] = (int) $course->id;
        }

        return ['section' => $section, 'course_ids' => $courseIds];
    }
}
