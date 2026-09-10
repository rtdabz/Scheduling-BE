<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Lock;

interface SchedulingScopeLock
{
    /**
     * @param  list<int>  $termIds
     */
    public function execute(array $termIds, callable $callback): mixed;
}
