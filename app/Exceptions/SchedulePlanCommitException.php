<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Scheduling\Domain\ConstraintViolation;
use RuntimeException;

final class SchedulePlanCommitException extends RuntimeException
{
    /** @param list<ConstraintViolation> $violations */
    public function __construct(
        private readonly array $violations,
        string $message = 'The schedule plan cannot be committed.',
    ) {
        parent::__construct($message);
    }

    /** @return list<ConstraintViolation> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'message' => $this->getMessage(),
            'violations' => array_map(
                static fn (ConstraintViolation $violation): array => $violation->toArray(),
                $this->violations,
            ),
        ];
    }
}
