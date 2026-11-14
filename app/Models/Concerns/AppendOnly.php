<?php

namespace App\Models\Concerns;

use LogicException;

trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Historical events cannot be updated. Record a separate correction event.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Historical events cannot be deleted.');
        });
    }
}
