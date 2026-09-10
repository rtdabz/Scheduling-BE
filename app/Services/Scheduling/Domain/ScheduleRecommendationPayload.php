<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class ScheduleRecommendationPayload implements SchedulingContract
{
    public const ENVELOPE_KEY = '_scheduling';

    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $legacyInput */
    public function __construct(
        public array $legacyInput,
        public GenerationConfiguration $configuration,
        public string $configurationFingerprint,
        public string $snapshotFingerprint,
        public array $confirmedWarningRuleIds = [],
        public ?SchedulePlan $schedulePlan = null,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->configurationFingerprint === '' || $this->snapshotFingerprint === '') {
            throw new InvalidArgumentException('Versioned recommendation payload fingerprints are required.');
        }
    }

    public static function fromPrepared(
        array $legacyInput,
        PreparedGenerationConfiguration $prepared,
        ?SchedulePlan $schedulePlan = null,
    ): self {
        unset($legacyInput['configuration_confirmation']);

        return new self(
            legacyInput: $legacyInput,
            configuration: $prepared->configuration,
            configurationFingerprint: $prepared->configurationFingerprint,
            snapshotFingerprint: $prepared->validation->snapshotFingerprint,
            confirmedWarningRuleIds: $prepared->confirmedWarningRuleIds,
            schedulePlan: $schedulePlan,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $envelope = $payload[self::ENVELOPE_KEY] ?? null;
        if (! is_array($envelope)) {
            throw new InvalidArgumentException('Recommendation payload does not contain a versioned scheduling envelope.');
        }

        unset($payload[self::ENVELOPE_KEY]);

        return new self(
            legacyInput: $payload,
            configuration: GenerationConfiguration::fromArray(
                is_array($envelope['generation_configuration'] ?? null)
                    ? $envelope['generation_configuration']
                    : [],
            ),
            configurationFingerprint: (string) ($envelope['configuration_fingerprint'] ?? ''),
            snapshotFingerprint: (string) ($envelope['snapshot_fingerprint'] ?? ''),
            confirmedWarningRuleIds: array_values(array_map(
                'strval',
                is_array($envelope['confirmed_warning_rule_ids'] ?? null)
                    ? $envelope['confirmed_warning_rule_ids']
                : [],
            )),
            schedulePlan: is_array($envelope['schedule_plan'] ?? null)
                ? SchedulePlan::fromArray($envelope['schedule_plan'])
                : null,
            schemaVersion: (int) ($envelope['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    /** @param array<string, mixed> $payload */
    public static function isVersioned(array $payload): bool
    {
        return is_array($payload[self::ENVELOPE_KEY] ?? null);
    }

    public function toArray(): array
    {
        $envelope = [
            'schema_version' => $this->schemaVersion,
            'generation_configuration_schema_version' => $this->configuration->schemaVersion,
            'recommendation_rows_schema_version' => 1,
            'generation_configuration' => $this->configuration->toArray(),
            'configuration_fingerprint' => $this->configurationFingerprint,
            'snapshot_fingerprint' => $this->snapshotFingerprint,
            'confirmed_warning_rule_ids' => $this->confirmedWarningRuleIds,
        ];

        if ($this->schedulePlan !== null) {
            $envelope['schedule_plan_schema_version'] = $this->schedulePlan->schemaVersion;
            $envelope['schedule_plan'] = $this->schedulePlan->toArray();
        }

        return [
            ...$this->legacyInput,
            self::ENVELOPE_KEY => $envelope,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
