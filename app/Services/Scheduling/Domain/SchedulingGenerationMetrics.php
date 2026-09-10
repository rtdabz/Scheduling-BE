<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class SchedulingGenerationMetrics implements SchedulingContract
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<string, int>  $prunedByConstraint
     * @param  list<string>  $retryReasons
     * @param  array<string, int>  $fallbackUsage
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $operation,
        public int $snapshotQueryCount = 0,
        public float $snapshotElapsedMs = 0.0,
        public int $variableCount = 0,
        public int $candidateCountBefore = 0,
        public int $candidateCountAfter = 0,
        public array $prunedByConstraint = [],
        public int $iterations = 0,
        public bool $searchLimitReached = false,
        public int $solverAttempts = 0,
        public array $retryReasons = [],
        public float $elapsedMs = 0.0,
        public array $fallbackUsage = [],
        public array $metadata = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->operation === '') {
            throw new InvalidArgumentException('Scheduling generation metrics require an operation name.');
        }

        if ($this->candidateCountAfter > $this->candidateCountBefore) {
            throw new InvalidArgumentException('Scheduling candidate counts are inconsistent.');
        }
    }

    public function prunedCandidateCount(): int
    {
        return $this->candidateCountBefore - $this->candidateCountAfter;
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            operation: (string) ($payload['operation'] ?? ''),
            snapshotQueryCount: max(0, (int) ($payload['snapshot_query_count'] ?? 0)),
            snapshotElapsedMs: max(0.0, (float) ($payload['snapshot_elapsed_ms'] ?? 0.0)),
            variableCount: max(0, (int) ($payload['variable_count'] ?? 0)),
            candidateCountBefore: max(0, (int) ($payload['candidate_count_before'] ?? 0)),
            candidateCountAfter: max(0, (int) ($payload['candidate_count_after'] ?? 0)),
            prunedByConstraint: self::integerMap($payload['pruned_by_constraint'] ?? []),
            iterations: max(0, (int) ($payload['iterations'] ?? 0)),
            searchLimitReached: (bool) ($payload['search_limit_reached'] ?? false),
            solverAttempts: max(0, (int) ($payload['solver_attempts'] ?? 0)),
            retryReasons: array_values(array_unique(array_map('strval', is_array($payload['retry_reasons'] ?? null) ? $payload['retry_reasons'] : []))),
            elapsedMs: max(0.0, (float) ($payload['elapsed_ms'] ?? 0.0)),
            fallbackUsage: self::integerMap($payload['fallback_usage'] ?? []),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            schemaVersion: (int) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'operation' => $this->operation,
            'snapshot_query_count' => $this->snapshotQueryCount,
            'snapshot_elapsed_ms' => round($this->snapshotElapsedMs, 3),
            'variable_count' => $this->variableCount,
            'candidate_count_before' => $this->candidateCountBefore,
            'candidate_count_after' => $this->candidateCountAfter,
            'pruned_candidate_count' => $this->prunedCandidateCount(),
            'pruned_by_constraint' => $this->prunedByConstraint,
            'iterations' => $this->iterations,
            'search_limit_reached' => $this->searchLimitReached,
            'solver_attempts' => $this->solverAttempts,
            'retry_reasons' => $this->retryReasons,
            'elapsed_ms' => round($this->elapsedMs, 3),
            'fallback_usage' => $this->fallbackUsage,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, int> */
    private static function integerMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $count) {
            $result[(string) $key] = max(0, (int) $count);
        }
        ksort($result);

        return $result;
    }
}
