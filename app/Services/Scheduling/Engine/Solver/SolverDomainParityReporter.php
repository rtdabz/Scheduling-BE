<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

final class SolverDomainParityReporter
{
    /**
     * @param  list<array<string, mixed>|SolverVariableDomain>  $legacyVariables
     * @return array{
     *     matches: bool,
     *     legacy_candidate_count: int,
     *     canonical_candidate_count: int,
     *     legacy_only: list<string>,
     *     canonical_only: list<string>
     * }
     */
    public function compare(array $legacyVariables, SolverDomainCompilation $canonical): array
    {
        $legacy = $this->candidateSignatures($legacyVariables);
        $current = $this->candidateSignatures($canonical->variables);
        $legacyOnly = array_values(array_diff($legacy, $current));
        $canonicalOnly = array_values(array_diff($current, $legacy));
        sort($legacyOnly);
        sort($canonicalOnly);

        return [
            'matches' => $legacyOnly === [] && $canonicalOnly === [],
            'legacy_candidate_count' => count($legacy),
            'canonical_candidate_count' => count($current),
            'legacy_only' => $legacyOnly,
            'canonical_only' => $canonicalOnly,
        ];
    }

    /**
     * @param  list<array<string, mixed>|SolverVariableDomain>  $variables
     * @return list<string>
     */
    private function candidateSignatures(array $variables): array
    {
        $signatures = [];
        foreach ($variables as $variable) {
            $domain = $variable instanceof SolverVariableDomain
                ? $variable
                : SolverVariableDomain::fromArray($variable);

            foreach ($domain->candidates as $candidate) {
                $normalized = $this->normalize($candidate);
                $signatures[] = hash('sha256', json_encode([
                    'course_id' => $domain->courseId,
                    'candidate' => $normalized,
                ], JSON_THROW_ON_ERROR));
            }
        }

        sort($signatures);

        return $signatures;
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            unset($value['_weekday_physical_available']);
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
