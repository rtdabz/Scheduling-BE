<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

final class SchedulingQueryCounter
{
    private int $queries = 0;

    public function record(): void
    {
        $this->queries++;
    }

    public function total(): int
    {
        return $this->queries;
    }
}
