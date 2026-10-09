<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Domain\SchedulingContract;

/**
 * Trusted service input, constructed after the caller's authorization.
 * Inputs may contain snapshots/models and are deliberately excluded from serialization.
 */
final readonly class RecommendationContext implements SchedulingContract
{
    /**
     * @param  array<string, mixed>  $inputs  Existing provider arguments, using their parameter names.
     * @param  array<string, mixed>  $scope  Known target identifiers; not a substitute for authorization.
     * @param  array<string, mixed>  $metadata  Caller evidence, separate from producer arguments.
     */
    public function __construct(
        public RecommendationSource $source,
        public array $inputs,
        public array $scope = [],
        public ?string $snapshotFingerprint = null,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'scope' => $this->scope,
            'snapshot_fingerprint' => $this->snapshotFingerprint,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
