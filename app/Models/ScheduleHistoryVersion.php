<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleHistoryVersion extends Model
{
    protected $fillable = [
        'semester_id', 'academic_year', 'semester', 'department_id', 'actor_user_id', 'action', 'source', 'reason', 'change_summary',
    ];

    protected $casts = ['change_summary' => 'array'];

    public function getScheduleIdAttribute(): ?int
    {
        if ($this->relationLoaded('items')) {
            return $this->items->first()?->original_schedule_id;
        }

        return $this->items()->value('original_schedule_id');
    }

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

    public function semesterRecord()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }
}
