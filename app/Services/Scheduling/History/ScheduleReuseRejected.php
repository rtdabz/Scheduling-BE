<?php

namespace App\Services\Scheduling\History;

use RuntimeException;

/** A historical schedule failed one or more compatibility checks; nothing was created. */
final class ScheduleReuseRejected extends RuntimeException
{
    /** @param  list<array{check: string, message: string, code?: string, section_name?: string, course_code?: string}>  $issues */
    public function __construct(private readonly array $issues, int $status = 422)
    {
        parent::__construct(count($issues) === 1
            ? 'This schedule cannot be reused: '.$issues[0]['message']
            : 'This schedule cannot be reused. Resolve the '.count($issues).' issues below and try again.', $status);
    }

    /** @return list<array{check: string, message: string, code?: string, section_name?: string, course_code?: string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function status(): int
    {
        return $this->getCode() ?: 422;
    }
}
