<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use DateTimeImmutable;
use App\Services\Scheduling\Engine\Solver\LegacyCspDomainCompiler;
use App\Services\Scheduling\Engine\Solver\CspSchedulingSolverAdapter;
use App\Services\Scheduling\Engine\Solver\SchedulingSolver;
use App\Services\Scheduling\Engine\Solver\SolverDomainCompilation;
use App\Services\Scheduling\Engine\Solver\SolverDomainParityReporter;
use App\Services\Scheduling\Engine\Solver\SolverResultMapper;
use App\Services\Scheduling\Engine\Solver\SolverVariableDomain;
use Tests\TestCase;

class SolverDomainContractsTest extends TestCase
{
    public function test_domain_compilation_round_trips_legacy_variables_and_metrics(): void
    {
        $compilation = new SolverDomainCompilation(
            snapshotFingerprint: str_repeat('a', 64),
            variables: [
                new SolverVariableDomain(7, [['blocks' => [['day' => 'Monday']]]], ['duration_slots' => 6]),
            ],
            candidateCountBefore: 3,
            candidateCountAfter: 1,
            prunedByConstraint: ['section_conflict' => 2],
        );

        $restored = SolverDomainCompilation::fromArray($compilation->toArray());

        $this->assertSame($compilation->toArray(), $restored->toArray());
        $this->assertSame(2, $restored->prunedCandidateCount());
        $this->assertSame(6, $restored->toLegacyVariables()[0]['duration_slots']);
    }

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
                        'term_id' => 2,
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
            termId: 2,
            departmentId: 5,
            sectionsById: [3 => ['id' => 3, 'term_id' => 2, 'department_id' => 5]],
            coursesById: [4 => ['id' => 4, 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1]],
            term: ['id' => 2],
        ));

        $this->assertSame($configuration->toArray(), $legacy->received);
        $this->assertCount(1, $candidates);
        $this->assertSame(420, $candidates[0]->qualityScore);
        $this->assertSame(1, $candidates[0]->metadata['rank']);
        $this->assertSame('Monday', $candidates[0]->rows[0]->day);
    }

    public function test_legacy_domain_compiler_and_parity_reporter_preserve_candidate_identity(): void
    {
        $legacy = [[
            'course_id' => 9,
            'duration_slots' => 4,
            'domain' => [
                ['mode' => 'online', 'blocks' => [['day' => 'Tuesday', 'start_time' => '08:00:00']]],
                ['mode' => 'online', 'blocks' => [['day' => 'Wednesday', 'start_time' => '08:00:00']]],
            ],
        ]];
        $compiled = (new LegacyCspDomainCompiler)->compile($legacy, str_repeat('b', 64));
        $matching = (new SolverDomainParityReporter)->compare($legacy, $compiled);
        $pruned = new SolverDomainCompilation(
            snapshotFingerprint: $compiled->snapshotFingerprint,
            variables: [$compiled->variables[0]->withCandidates([$compiled->variables[0]->candidates[1]])],
            candidateCountBefore: 2,
            candidateCountAfter: 1,
        );
        $mismatch = (new SolverDomainParityReporter)->compare($legacy, $pruned);

        $this->assertTrue($matching['matches']);
        $this->assertSame(2, $matching['canonical_candidate_count']);
        $this->assertFalse($mismatch['matches']);
        $this->assertCount(1, $mismatch['legacy_only']);
        $this->assertSame([], $mismatch['canonical_only']);
    }

    public function test_solver_port_resolves_to_the_legacy_compatibility_adapter(): void
    {
        $this->assertInstanceOf(CspSchedulingSolverAdapter::class, app(SchedulingSolver::class));
    }

    public function test_snapshot_rollout_guard_rejects_direct_database_loader_execution(): void
    {
        config()->set('app.require_scheduling_snapshot', true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A SchedulingSnapshot is required');

        (new CspSolver)->solveRanked(sectionId: 1, courseIds: [1]);
    }
}
