<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Solver;

use App\Services\Scheduling\Domain\SchedulingContract;
use InvalidArgumentException;

final readonly class SolverDomainCompilation implements SchedulingContract
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<SolverVariableDomain>  $variables
     * @param  array<string, int>  $prunedByConstraint
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $snapshotFingerprint,
        public array $variables,
        public int $candidateCountBefore,
        public int $candidateCountAfter,
        public array $prunedByConstraint = [],
        public array $metadata = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->snapshotFingerprint === '') {
            throw new InvalidArgumentException('A solver domain compilation requires a snapshot fingerprint.');
        }

        if ($this->candidateCountBefore < $this->candidateCountAfter || $this->candidateCountAfter < 0) {
            throw new InvalidArgumentException('Solver domain candidate counts are inconsistent.');
        }

        foreach ($this->variables as $variable) {
            if (! $variable instanceof SolverVariableDomain) {
                throw new InvalidArgumentException('Solver domain variables must use SolverVariableDomain.');
            }
        }
    }

    public function prunedCandidateCount(): int
    {
        return $this->candidateCountBefore - $this->candidateCountAfter;
    }

    public function toLegacyVariables(): array
    {
        return array_map(
            static fn (SolverVariableDomain $variable): array => $variable->toArray(),
            $this->variables,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            snapshotFingerprint: (string) ($payload['snapshot_fingerprint'] ?? ''),
            variables: array_map(
                static fn (array $variable): SolverVariableDomain => SolverVariableDomain::fromArray($variable),
                array_values(is_array($payload['variables'] ?? null) ? $payload['variables'] : []),
            ),
            candidateCountBefore: (int) ($payload['candidate_count_before'] ?? 0),
            candidateCountAfter: (int) ($payload['candidate_count_after'] ?? 0),
            prunedByConstraint: is_array($payload['pruned_by_constraint'] ?? null) ? $payload['pruned_by_constraint'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            schemaVersion: (int) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'snapshot_fingerprint' => $this->snapshotFingerprint,
            'candidate_count_before' => $this->candidateCountBefore,
            'candidate_count_after' => $this->candidateCountAfter,
            'pruned_candidate_count' => $this->prunedCandidateCount(),
            'pruned_by_constraint' => $this->prunedByConstraint,
            'variables' => $this->toLegacyVariables(),
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
