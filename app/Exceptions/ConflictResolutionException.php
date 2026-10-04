<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

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
