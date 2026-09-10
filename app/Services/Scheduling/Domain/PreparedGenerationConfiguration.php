<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

final readonly class PreparedGenerationConfiguration implements SchedulingContract
{
    /** @param list<string> $confirmedWarningRuleIds */
    public function __construct(
        public GenerationConfiguration $configuration,
        public GenerationConfigurationValidationResult $validation,
        public string $configurationFingerprint,
        public array $confirmedWarningRuleIds = [],
    ) {}

    public function toArray(): array
    {
        return [
            'configuration' => $this->configuration->toArray(),
            'configuration_fingerprint' => $this->configurationFingerprint,
            'snapshot_fingerprint' => $this->validation->snapshotFingerprint,
            'confirmed_warning_rule_ids' => $this->confirmedWarningRuleIds,
            'validation' => $this->validation->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
