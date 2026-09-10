<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Lock;

use App\Exceptions\ScheduleConflictException;
use Illuminate\Support\Facades\DB;

final class DatabaseSchedulingScopeLock implements SchedulingScopeLock
{
    private const TIMEOUT_SECONDS = 10;

    public function execute(array $termIds, callable $callback): mixed
    {
        $termIds = array_values(array_unique(array_filter(
            array_map('intval', $termIds),
            static fn (int $termId): bool => $termId > 0,
        )));
        sort($termIds);

        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || $termIds === []) {
            return $callback();
        }

        $acquired = [];

        try {
            foreach ($termIds as $termId) {
                $lockName = sprintf('wicars:schedule-write:%d', $termId);
                $granted = $connection->selectOne(
                    'SELECT GET_LOCK(?, ?) AS granted',
                    [$lockName, self::TIMEOUT_SECONDS],
                );

                if ((int) ($granted->granted ?? 0) !== 1) {
                    throw new ScheduleConflictException(
                        [[
                            'rule' => 'concurrent_write',
                            'message' => 'Another schedule save for this term is still in progress. Please retry in a moment.',
                            'term_id' => $termId,
                        ]],
                        'Another schedule save for this term is still in progress. Please retry in a moment.',
                    );
                }

                $acquired[] = $lockName;
            }

            return $callback();
        } finally {
            foreach (array_reverse($acquired) as $lockName) {
                $connection->statement('DO RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }
}
