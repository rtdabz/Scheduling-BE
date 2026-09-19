<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use DateTimeImmutable;
use App\Services\Scheduling\Engine\Solver\CspSchedulingSolverAdapter;
use App\Services\Scheduling\Engine\Solver\SchedulingSolver;
use App\Services\Scheduling\Engine\Solver\SolverResultMapper;
use RuntimeException;
use Tests\TestCase;

class SolverDomainContractsTest extends TestCase
{
    public function test_legacy_solver_adapter_preserves_ranked_solver_results(): void
    {
        $legacy = new class extends CspSolver
        {
            public array $received = [];

            public function solveRankedFromSchema(array $input): array
            {
                $this->received = $input;

                return [[
                    'rank' => 1,
                    'score' => 420,
                    'schedules' => [[
                        'semester_id' => 2,
                        'section_id' => 3,
                        'course_id' => 4,
                        'department_id' => 5,
                        'day' => 'Monday',
                        'start_time' => '08:00:00',
                        'end_time' => '09:30:00',
                        'mode' => 'online',
                        'status' => 'draft',
                    ]],
                ]];
            }
        };
        $adapter = new CspSchedulingSolverAdapter($legacy, new SolverResultMapper);
        $configuration = GenerationConfiguration::fromArray([
            'section_id' => 3,
            'course_ids' => [4],
            'delivery_mode' => 'online',
            'seed' => 17,
        ]);

        $candidates = $adapter->solve($configuration, new SchedulingSnapshot(
            fingerprint: str_repeat('a', 64),
            capturedAt: new DateTimeImmutable,
            semesterId: 2,
            departmentId: 5,
            sectionsById: [3 => ['id' => 3, 'semester_id' => 2, 'department_id' => 5]],
            coursesById: [4 => ['id' => 4, 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1]],
            semester: ['id' => 2],
        ));

        $this->assertSame($configuration->toArray(), $legacy->received);
        $this->assertCount(1, $candidates);
        $this->assertSame(420, $candidates[0]->qualityScore);
        $this->assertSame(1, $candidates[0]->metadata['rank']);
        $this->assertSame('Monday', $candidates[0]->rows[0]->day);
    }

    public function test_solver_port_resolves_to_the_legacy_compatibility_adapter(): void
    {
        $this->assertInstanceOf(CspSchedulingSolverAdapter::class, app(SchedulingSolver::class));
    }

    /**
     * The snapshot is the solver's only data source, so one captured for
     * another department is refused rather than silently judged against, as
     * the removed database fallback used to do.
     */
    public function test_solver_refuses_a_snapshot_for_another_department(): void
    {
        $solver = new CspSolver;
        $solver->setInputSnapshot(new SchedulingSnapshot(
            fingerprint: 'other-department',
            capturedAt: new DateTimeImmutable,
            semesterId: 1,
            departmentId: 1,
            sectionsById: [1 => ['id' => 1, 'semester_id' => 1, 'department_id' => 2, 'year_level' => '1', 'semester' => '1st', 'status' => 'active']],
            semester: ['id' => 1, 'semester' => '1st'],
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different semester or department');

        $solver->solveRanked(sectionId: 1, courseIds: [1]);
    }
}
