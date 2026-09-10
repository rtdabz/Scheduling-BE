<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Solver;

use App\Services\Scheduling\Constraints\SchedulingConstraintEvaluationContextFactory;
use App\Services\Scheduling\Constraints\SchedulingConstraintKernel;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;

final class SchedulingHardConstraintPropagator
{
    /** Rules currently owned by the legacy persisted-conflict pruning pass. */
    public const PROPAGATED_RULES = [
        'section_conflict',
        'subject_section_time_conflict',
        'faculty_conflict',
        'room_conflict',
        'room_capacity_conflict',
        'online_capacity_conflict',
    ];

    public function __construct(
        private readonly SchedulingConstraintKernel $kernel,
        private readonly CspCandidateRowMapper $rows,
        private readonly LegacyCspDomainCompiler $compiler,
        private readonly SchedulingConstraintEvaluationContextFactory $contexts,
    ) {}

    /**
     * @param  list<array<string, mixed>|SolverVariableDomain>  $variables
     */
    public function propagate(
        array $variables,
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
    ): SolverDomainCompilation {
        $compiled = $this->compiler->compile($variables, $snapshot->fingerprint);
        $domains = $compiled->variables;
        $before = $compiled->candidateCountBefore;
        $prunedByConstraint = [];
        $context = $this->contexts->forGeneration($configuration, $snapshot);
        $propagated = [];

        foreach ($domains as $variable) {
            $candidates = [];
            foreach ($variable->candidates as $candidate) {
                $candidateRows = $this->rows->map($candidate, $configuration, $snapshot);
                $violations = $this->candidateViolations(
                    $candidateRows,
                    $context->additionalRows,
                    $snapshot,
                    $context->ignoreScheduleIds,
                );

                if ($violations === []) {
                    $candidates[] = $candidate;

                    continue;
                }

                $ruleId = $violations[0]->ruleId;
                $prunedByConstraint[$ruleId] = ($prunedByConstraint[$ruleId] ?? 0) + 1;
            }

            $propagated[] = $variable->withCandidates($candidates);
        }

        ksort($prunedByConstraint);
        $after = array_sum(array_map(
            static fn (SolverVariableDomain $variable): int => count($variable->candidates),
            $propagated,
        ));

        return new SolverDomainCompilation(
            snapshotFingerprint: $snapshot->fingerprint,
            variables: $propagated,
            candidateCountBefore: $before,
            candidateCountAfter: $after,
            prunedByConstraint: $prunedByConstraint,
            metadata: [
                ...$compiled->metadata,
                'section_id' => $configuration->sectionId,
                'course_ids' => $configuration->courseIds,
                'propagated_rules' => self::PROPAGATED_RULES,
            ],
        );
    }

    /**
     * @param  list<ScheduleRow>  $candidateRows
     * @param  list<ScheduleRow>  $tentativeRows
     * @param  list<int>  $ignoreScheduleIds
     * @return list<ConstraintViolation>
     */
    private function candidateViolations(
        array $candidateRows,
        array $tentativeRows,
        SchedulingSnapshot $snapshot,
        array $ignoreScheduleIds,
    ): array {
        $violations = [];

        foreach ($candidateRows as $index => $row) {
            $otherRows = [...$tentativeRows];
            foreach ($candidateRows as $otherIndex => $otherRow) {
                if ($otherIndex !== $index) {
                    $otherRows[] = $otherRow;
                }
            }

            foreach ($this->kernel->evaluateRow($row, $snapshot, $otherRows, $ignoreScheduleIds) as $violation) {
                if (in_array($violation->ruleId, self::PROPAGATED_RULES, true)) {
                    $violations[$violation->ruleId] ??= $violation;
                }
            }
        }

        return array_values($violations);
    }
}
