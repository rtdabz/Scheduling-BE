<?php

namespace App\Exceptions;

use RuntimeException;

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
