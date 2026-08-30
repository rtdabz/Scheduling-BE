<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleHistoryVersion extends Model
{
    protected $fillable = [
        'term_id', 'department_id', 'actor_user_id', 'action', 'source', 'reason', 'change_summary',
    ];

    protected $casts = ['change_summary' => 'array'];

    /**
     * Compatibility accessor for consumers that previously read a single
     * schedule_id from the legacy history row. Aggregate versions keep the
     * authoritative IDs on history items, so this returns the first item ID.
     */
    public function getScheduleIdAttribute(): ?int
    {
        if ($this->relationLoaded('items')) {
            return $this->items->first()?->original_schedule_id;
        }

        return $this->items()->value('original_schedule_id');
    }

    /** Legacy-compatible alias for the old history model's changes payload. */
    public function getChangesAttribute(): array
    {
        return $this->change_summary ?? [];
    }

    public function items()
    {
        return $this->hasMany(ScheduleHistoryItem::class, 'history_version_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(SchedulingAuditLog::class, 'history_version_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }
}
