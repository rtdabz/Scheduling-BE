<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised inside a schedule write transaction when the candidate operations
 * conflict with persisted rows or with each other.
 *
 * Conflict validation runs inside the same transaction that performs the write
 * so that the snapshot it reads cannot change before the write commits. Because
 * a 422 cannot be returned from inside the transaction closure, violations are
 * carried out through this exception, which also rolls the transaction back.
 */
class ScheduleConflictException extends RuntimeException
{
    /** @param list<array<string, mixed>> $violations */
    public function __construct(
        private readonly array $violations,
        string $message = 'Schedule operation conflicts with existing entries or intra-batch schedules.',
    ) {
        parent::__construct($message);
    }

    /** @return list<array<string, mixed>> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'message' => $this->getMessage(),
            'violations' => $this->violations,
        ];
    }
}
