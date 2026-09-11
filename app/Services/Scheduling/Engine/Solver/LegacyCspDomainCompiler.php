<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

final class LegacyCspDomainCompiler
{
    /** @param list<array<string, mixed>|SolverVariableDomain> $variables */
    public function compile(array $variables, string $snapshotFingerprint): SolverDomainCompilation
    {
        $domains = array_map(
            static fn (array|SolverVariableDomain $variable): SolverVariableDomain => $variable instanceof SolverVariableDomain
                ? $variable
                : SolverVariableDomain::fromArray($variable),
            array_values($variables),
        );
        $candidateCount = array_sum(array_map(
            static fn (SolverVariableDomain $variable): int => count($variable->candidates),
            $domains,
        ));

        return new SolverDomainCompilation(
            snapshotFingerprint: $snapshotFingerprint,
            variables: $domains,
            candidateCountBefore: $candidateCount,
            candidateCountAfter: $candidateCount,
            metadata: ['compiler' => 'legacy_csp_domain_adapter'],
        );
    }
}
