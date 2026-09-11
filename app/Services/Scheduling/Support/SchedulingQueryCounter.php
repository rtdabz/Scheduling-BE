<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Support;

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
