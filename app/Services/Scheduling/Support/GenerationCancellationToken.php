<?php

namespace App\Services\Scheduling\Support;

use App\Exceptions\GenerationCancelledException;

class GenerationCancellationToken
{
    private bool $cancelled = false;

    private float $lastCheckedAt = 0.0;

    /**
     * @param  callable(): bool  $probe  returns true once the run is cancelled
     */
    public function __construct(
        private $probe,
        private readonly float $minimumIntervalSeconds = 2.0,
    ) {}

    public static function none(): self
    {
        return new self(static fn (): bool => false, PHP_FLOAT_MAX);
    }

    public function isCancelled(): bool
    {
        if ($this->cancelled) {
            return true;
        }

        $now = microtime(true);
        if ($now - $this->lastCheckedAt < $this->minimumIntervalSeconds) {
            return false;
        }
        $this->lastCheckedAt = $now;

        return $this->cancelled = (bool) ($this->probe)();
    }

    /**
     * @throws GenerationCancelledException
     */
    public function abortIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new GenerationCancelledException;
        }
    }
}
