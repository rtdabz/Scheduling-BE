<?php

namespace App\Support;

use Illuminate\Database\QueryException;

final class DatabaseErrors
{
    public static function isUniqueViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        return $driverCode === 1062
            || $driverCode === 2067
            || ($exception->errorInfo[0] ?? null) === '23505'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }

    /** Deadlock or lock-wait timeout: the operation can be retried safely. */
    public static function isLockContention(QueryException $exception): bool
    {
        return in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)
            || str_contains($exception->getMessage(), 'database is locked');
    }

    public static function mentions(QueryException $exception, string ...$needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($exception->getMessage(), $needle)) {
                return true;
            }
        }

        return false;
    }
}
