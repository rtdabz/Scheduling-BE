<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleHistoryItem extends Model
{
    protected $fillable = [
        'history_version_id', 'original_schedule_id', 'section_id', 'course_id', 'faculty_id', 'room_id',
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

    public function section() { return $this->belongsTo(Sections::class, 'section_id'); }
    public function course() { return $this->belongsTo(Course::class, 'course_id'); }
}
