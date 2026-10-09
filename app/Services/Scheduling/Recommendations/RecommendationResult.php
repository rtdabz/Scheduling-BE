<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Domain\SchedulingContract;

final readonly class RecommendationResult implements SchedulingContract
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<array-key, mixed>  $legacyPayload  Original response/list, unchanged by normalization.
     * @param  list<RecommendationOption>  $options  Internal normalized options; never authorization to apply.
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public RecommendationContext $context,
        public array $legacyPayload,
        public array $options,
        public array $metadata,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<array<string, mixed>>  $options
     * @param  array<string, mixed>  $metadata
     */
    public static function adapt(
        RecommendationContext $context,
        array $payload,
        array $options,
        string $verificationScope,
        string $verificationStatus = 'legacy_checks_only',
        array $metadata = [],
    ): self {
        return new self(
            $context,
            $payload,
            array_map(
                static function (array $option) use ($context, $verificationScope, $verificationStatus): RecommendationOption {
                    $status = $verificationStatus;
                    if (in_array($context->source, [
                        RecommendationSource::Configuration,
                        RecommendationSource::Feasibility,
                        RecommendationSource::Search,
                        RecommendationSource::PreferredDays,
                    ], true)) {
                        $status = self::generationVerificationStatus($option);
                    }

                    return new RecommendationOption($option, $status, $verificationScope);
                },
                $options,
            ),
            $metadata,
        );
    }

    /** @param array<string, mixed> $option */
    private static function generationVerificationStatus(array $option): string
    {
        $adjustments = $option['adjustments'] ?? [];
        if (! is_array($adjustments) || $adjustments === []) {
            return 'guidance';
        }

        foreach ($adjustments as $adjustment) {
            if (! is_array($adjustment)
                || ! GenerationAdjustmentInterpreter::supports($adjustment)) {
                return 'guidance';
            }
        }

        return 'requires_regeneration';
    }

    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'context' => $this->context->toArray(),
            'options' => array_map(static fn (RecommendationOption $option): array => $option->toArray(), $this->options),
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
