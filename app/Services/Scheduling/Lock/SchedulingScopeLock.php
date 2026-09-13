<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Lock;

interface SchedulingScopeLock
{
    /**
     * @param  list<int>  $semesterIds
     */
    public function execute(array $semesterIds, callable $callback): mixed;
}
