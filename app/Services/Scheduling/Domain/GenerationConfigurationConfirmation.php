<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class GenerationConfigurationConfirmation implements SchedulingContract
{
    public const SCHEMA_VERSION = 1;

    /** @param list<string> $confirmedWarningRuleIds */
    public function __construct(
        public string $configurationFingerprint,
        public array $confirmedWarningRuleIds,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/', $this->configurationFingerprint)) {
            throw new InvalidArgumentException('Configuration confirmation requires a SHA-256 fingerprint.');
        }

        foreach ($this->confirmedWarningRuleIds as $ruleId) {
            if (! is_string($ruleId) || $ruleId === '') {
                throw new InvalidArgumentException('Confirmed warning rule IDs must be non-empty strings.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            configurationFingerprint: (string) ($payload['configuration_fingerprint'] ?? ''),
            confirmedWarningRuleIds: array_values(array_unique(array_map(
                'strval',
                is_array($payload['confirmed_warning_rule_ids'] ?? null)
                    ? $payload['confirmed_warning_rule_ids']
                    : [],
            ))),
            schemaVersion: (int) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'configuration_fingerprint' => $this->configurationFingerprint,
            'confirmed_warning_rule_ids' => $this->confirmedWarningRuleIds,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
