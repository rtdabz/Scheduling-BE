<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\ActorIdentity;
use LogicException;

trait PreservesApprovalActors
{
    public static function bootPreservesApprovalActors(): void
    {
        static::creating(function (self $record): void {
            $record->actor_snapshots = [];
            $record->captureApprovalActors();
        });
        static::updating(function (self $record): void {
            foreach (static::RECORDED_ACTORS as $column => $timeColumn) {
                if (in_array($column, static::REPEATABLE_ACTORS, true)) {
                    continue;
                }
                if (($record->getRawOriginal($column) !== null || ($timeColumn && $record->getRawOriginal($timeColumn) !== null))
                    && ($record->isDirty($column) || ($timeColumn && $record->isDirty($timeColumn)))) {
                    throw new LogicException('A recorded approval actor and decision time cannot be replaced.');
                }
            }
            $record->captureApprovalActors();
        });
    }

    private function captureApprovalActors(): void
    {
        $snapshots = $this->actor_snapshots;
        foreach (static::RECORDED_ACTORS as $column => $timeColumn) {
            if ($this->{$column} !== null && (! $this->exists || $this->isDirty($column) || ($timeColumn && $this->isDirty($timeColumn)))) {
                $snapshots[$column] = ActorIdentity::snapshot(User::withTrashed()->find($this->{$column}));
            }
        }
        $this->actor_snapshots = $snapshots;
    }
}
