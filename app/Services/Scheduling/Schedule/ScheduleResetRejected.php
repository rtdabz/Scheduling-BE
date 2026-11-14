<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use RuntimeException;

/**
 * Thrown to reject a whole Reset request. Inside the reset transaction it rolls back every
 * deletion; the controller turns it into the carried HTTP status and payload.
 */
final class ScheduleResetRejected extends RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(public readonly int $status, public readonly array $payload)
    {
        parent::__construct((string) ($payload['message'] ?? 'The reset was rejected.'));
    }
}
