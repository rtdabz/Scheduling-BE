<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Scheduling\Domain\GenerationConfigurationValidationResult;
use App\Services\Scheduling\Domain\SchedulingGenerationMetrics;
use RuntimeException;

final class GenerationConfigurationConfirmationException extends RuntimeException
{
    /** @param list<string> $requiredWarningRuleIds */
    public function __construct(
        private readonly GenerationConfigurationValidationResult $validation,
        private readonly string $configurationFingerprint,
        private readonly array $requiredWarningRuleIds,
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $snapshotElapsedMs = (float) ($this->validation->metadata['snapshot_elapsed_ms'] ?? 0.0);

        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'configuration_confirmation' => [
                'schema_version' => 1,
                'configuration_fingerprint' => $this->configurationFingerprint,
                'required_warning_rule_ids' => $this->requiredWarningRuleIds,
            ],
            'configuration_validation' => $this->validation->toArray(),
            'generation_metrics' => (new SchedulingGenerationMetrics(
                operation: 'generation_configuration_validation',
                snapshotQueryCount: (int) ($this->validation->metadata['snapshot_query_count'] ?? 0),
                snapshotElapsedMs: $snapshotElapsedMs,
                elapsedMs: $snapshotElapsedMs,
                metadata: [
                    'configuration_fingerprint' => $this->configurationFingerprint,
                    'snapshot_fingerprint' => $this->validation->snapshotFingerprint,
                    'solver_invoked' => false,
                ],
            ))->toArray(),
        ];
    }
}
