<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleHistoryItem extends Model
{
    protected $fillable = [
        'history_version_id', 'original_schedule_id',
        'before_snapshot', 'after_snapshot', 'snapshot_metadata',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'snapshot_metadata' => 'array',
    ];

    public function version()
    {
        return $this->belongsTo(ScheduleHistoryVersion::class, 'history_version_id');
    }

}
