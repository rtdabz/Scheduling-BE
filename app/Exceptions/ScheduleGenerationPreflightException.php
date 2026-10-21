<?php

namespace App\Exceptions;

use RuntimeException;

class ScheduleGenerationPreflightException extends RuntimeException
{
    /** @param list<array<string, mixed>> $issues */
    public function __construct(
        private readonly array $issues,
    ) {
        parent::__construct($issues[0]['message'] ?? 'Schedule generation preflight failed.');
    }

    /** @return list<array<string, mixed>> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'error_code' => 'schedule_generation_preflight_failed',
            'message' => $this->getMessage(),
            'issues' => $this->issues,
        ];
    }
}
