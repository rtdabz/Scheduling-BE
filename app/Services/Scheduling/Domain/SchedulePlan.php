<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class SchedulePlan implements SchedulingContract
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<ScheduleRow>  $rows
     * @param  list<ConstraintViolation>  $violations
     * @param  list<array<string, mixed>>  $recommendations
     * @param  list<array<string, mixed>>  $appliedAdjustments
     * @param  list<array<string, mixed>>  $unresolvedResources
     * @param  array<string, int|float>  $scores
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $planId,
        public GenerationConfiguration $configuration,
        public string $snapshotFingerprint,
        public SchedulePlanStatus $status,
        public array $rows = [],
        public array $violations = [],
        public array $recommendations = [],
        public array $appliedAdjustments = [],
        public array $unresolvedResources = [],
        public array $scores = [],
        public array $metadata = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->planId === '' || $this->snapshotFingerprint === '') {
            throw new InvalidArgumentException('Schedule plan identity is incomplete.');
        }

        foreach ($this->rows as $row) {
            if (! $row instanceof ScheduleRow) {
                throw new InvalidArgumentException('Schedule plan rows must be ScheduleRow instances.');
            }
        }

        foreach ($this->violations as $violation) {
            if (! $violation instanceof ConstraintViolation) {
                throw new InvalidArgumentException('Schedule plan violations must be ConstraintViolation instances.');
            }
        }

        if ($this->status === SchedulePlanStatus::Committed && ($this->rows === [] || $this->hasHardViolations())) {
            throw new InvalidArgumentException('A committed schedule plan requires rows and cannot contain hard violations.');
        }
    }

    public function hasHardViolations(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity === 'hard') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            planId: (string) ($payload['plan_id'] ?? ''),
            configuration: GenerationConfiguration::fromArray(
                is_array($payload['configuration'] ?? null) ? $payload['configuration'] : [],
            ),
            snapshotFingerprint: (string) ($payload['snapshot_fingerprint'] ?? ''),
            status: SchedulePlanStatus::from((string) ($payload['status'] ?? SchedulePlanStatus::Invalid->value)),
            rows: array_map(
                static fn (array $row): ScheduleRow => ScheduleRow::fromArray($row),
                array_values(is_array($payload['schedules'] ?? null) ? $payload['schedules'] : []),
            ),
            violations: array_map(
                static fn (array $violation): ConstraintViolation => ConstraintViolation::fromArray($violation),
                array_values(is_array($payload['violations'] ?? null) ? $payload['violations'] : []),
            ),
            recommendations: array_values(is_array($payload['recommendations'] ?? null) ? $payload['recommendations'] : []),
            appliedAdjustments: array_values(is_array($payload['applied_adjustments'] ?? null) ? $payload['applied_adjustments'] : []),
            unresolvedResources: array_values(is_array($payload['unresolved_resources'] ?? null) ? $payload['unresolved_resources'] : []),
            scores: is_array($payload['scores'] ?? null) ? $payload['scores'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            schemaVersion: (int) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    public function requiresConfirmation(): bool
    {
        $confirmedRuleIds = array_values(array_map(
            'strval',
            is_array($this->metadata['confirmed_violation_rule_ids'] ?? null)
                ? $this->metadata['confirmed_violation_rule_ids']
                : [],
        ));

        return $this->appliedAdjustments !== []
            || $this->unresolvedResources !== []
            || array_filter(
                $this->violations,
                static fn (ConstraintViolation $violation): bool => $violation->severity === 'warning'
                    && ! in_array($violation->ruleId, $confirmedRuleIds, true),
            ) !== [];
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'plan_id' => $this->planId,
            'configuration' => $this->configuration->toArray(),
            'snapshot_fingerprint' => $this->snapshotFingerprint,
            'status' => $this->status->value,
            'schedules' => array_map(static fn (ScheduleRow $row): array => $row->toArray(), $this->rows),
            'violations' => array_map(
                static fn (ConstraintViolation $violation): array => $violation->toArray(),
                $this->violations,
            ),
            'recommendations' => $this->recommendations,
            'applied_adjustments' => $this->appliedAdjustments,
            'unresolved_resources' => $this->unresolvedResources,
            'scores' => $this->scores,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
