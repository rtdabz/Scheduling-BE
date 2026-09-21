<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a conflict resolution cannot proceed for a reason that is not a
 * rule violation: the conflict is not there any more, the row named is not part
 * of it, or its placement is locked at an approval stage.
 *
 * Rule violations keep travelling as ScheduleConflictException so the existing
 * violation payloads and the "assign anyway" affordance stay unchanged.
 */
class ConflictResolutionException extends RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['message' => $this->getMessage()] + $this->payload;
    }
}
