<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use Closure;
use InvalidArgumentException;
use LogicException;

/** Coordinates compatibility providers; eligibility, ranking and writes retain their existing owners. */
final readonly class RecommendationEngine
{
    /** @param array<string, Closure(): RecommendationProvider> $providers Lazy, request-local provider factories. */
    public function __construct(private array $providers) {}

    /** @return list<string> */
    public function sources(): array
    {
        return array_keys($this->providers);
    }

    public function recommend(RecommendationContext $context): RecommendationResult
    {
        $factory = $this->providers[$context->source->value] ?? null;
        if (! $factory instanceof Closure) {
            throw new InvalidArgumentException('No provider registered for '.$context->source->value.'.');
        }

        $result = $factory()->recommend($context);
        if ($result->context !== $context) {
            throw new LogicException('A recommendation provider returned a different request context.');
        }

        return $result;
    }
}
