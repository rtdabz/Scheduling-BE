<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class GenerationConfigurationValidationResult implements SchedulingContract
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<ConstraintViolation>  $violations
     * @param  list<GenerationConfigurationRecommendation>  $recommendations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public GenerationConfiguration $configuration,
        public string $snapshotFingerprint,
        public array $violations = [],
        public array $recommendations = [],
        public array $metadata = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->snapshotFingerprint === '') {
            throw new InvalidArgumentException('Configuration validation requires a snapshot fingerprint.');
        }

        foreach ($this->violations as $violation) {
            if (! $violation instanceof ConstraintViolation) {
                throw new InvalidArgumentException('Configuration validation violations must use ConstraintViolation.');
            }
        }

        foreach ($this->recommendations as $recommendation) {
            if (! $recommendation instanceof GenerationConfigurationRecommendation) {
                throw new InvalidArgumentException('Configuration recommendations must use the recommendation contract.');
            }
        }
    }

    public function canGenerate(): bool
    {
        return ! $this->hasSeverity('hard');
    }

    public function requiresConfirmation(): bool
    {
        return $this->canGenerate() && $this->hasSeverity('warning');
    }

    public function status(): string
    {
        if (! $this->canGenerate()) {
            return 'invalid';
        }

        return $this->requiresConfirmation() ? 'confirmation_required' : 'valid';
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            configuration: GenerationConfiguration::fromArray(
                is_array($payload['configuration'] ?? null) ? $payload['configuration'] : [],
            ),
            snapshotFingerprint: (string) ($payload['snapshot_fingerprint'] ?? ''),
            violations: array_map(
                static fn (array $violation): ConstraintViolation => ConstraintViolation::fromArray($violation),
                array_values(is_array($payload['violations'] ?? null) ? $payload['violations'] : []),
            ),
            recommendations: array_map(
                static fn (array $recommendation): GenerationConfigurationRecommendation => GenerationConfigurationRecommendation::fromArray($recommendation),
                array_values(is_array($payload['recommendations'] ?? null) ? $payload['recommendations'] : []),
            ),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            schemaVersion: (int) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'status' => $this->status(),
            'can_generate' => $this->canGenerate(),
            'requires_confirmation' => $this->requiresConfirmation(),
            'configuration' => $this->configuration->toArray(),
            'snapshot_fingerprint' => $this->snapshotFingerprint,
            'violations' => array_map(
                static fn (ConstraintViolation $violation): array => $violation->toArray(),
                $this->violations,
            ),
            'recommendations' => array_map(
                static fn (GenerationConfigurationRecommendation $recommendation): array => $recommendation->toArray(),
                $this->recommendations,
            ),
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function hasSeverity(string $severity): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
