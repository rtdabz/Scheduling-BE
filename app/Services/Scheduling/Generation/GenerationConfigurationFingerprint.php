<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Support\SchedulingSnapshotFingerprint;

final class GenerationConfigurationFingerprint
{
    public function __construct(
        private readonly SchedulingSnapshotFingerprint $fingerprint,
    ) {}

    public function calculate(GenerationConfiguration $configuration): string
    {
        return $this->fingerprint->calculate([
            'contract' => GenerationConfiguration::class,
            'schema_version' => $configuration->schemaVersion,
            'configuration' => $configuration->toArray(),
        ]);
    }
}
