<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Lock;

use App\Exceptions\ScheduleConflictException;
use Illuminate\Support\Facades\DB;

final class DatabaseSchedulingScopeLock implements SchedulingScopeLock
{
    private const TIMEOUT_SECONDS = 10;

    public function execute(array $semesterIds, callable $callback): mixed
    {
        $semesterIds = array_values(array_unique(array_filter(
            array_map('intval', $semesterIds),
            static fn (int $semesterId): bool => $semesterId > 0,
        )));
        sort($semesterIds);

        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || $semesterIds === []) {
            return $callback();
        }

        $acquired = [];

        try {
            foreach ($semesterIds as $semesterId) {
                $lockName = sprintf('wicars:schedule-write:%d', $semesterId);
                $granted = $connection->selectOne(
                    'SELECT GET_LOCK(?, ?) AS granted',
                    [$lockName, self::TIMEOUT_SECONDS],
                );

                if ((int) ($granted->granted ?? 0) !== 1) {
                    throw new ScheduleConflictException(
                        [[
                            'rule' => 'concurrent_write',
                            'message' => 'Another schedule save for this semester is still in progress. Please retry in a moment.',
                            'semester_id' => $semesterId,
                        ]],
                        'Another schedule save for this semester is still in progress. Please retry in a moment.',
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
