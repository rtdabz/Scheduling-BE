<?php

namespace App\Services\Scheduling\Support;

use App\Exceptions\GenerationCancelledException;

/**
 * Cooperative cancellation for a queued generation run.
 *
 * The generator cannot be interrupted from outside its worker, so the search
 * polls this token at the batch boundaries it already has (retry strategies,
 * candidate orders, per-section placement). The probe is throttled because
 * those boundaries are hit far more often than a user can click Cancel: at one
 * lookup per interval the cost is negligible next to the placement work
 * between two checks.
 */
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

    /** A token that never reports cancellation, for synchronous callers. */
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
