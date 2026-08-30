<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulingAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'scheduling_audit_logs';

    protected $fillable = [
        'user_id',
        'schedule_recommendation_id',
        'history_version_id',
        'schedule_submission_id',
        'term_id',
        'section_id',
        'department_id',
        'action',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function recommendation()
    {
        return $this->belongsTo(ScheduleRecommendation::class, 'schedule_recommendation_id');
    }

    public function historyVersion()
    {
        return $this->belongsTo(ScheduleHistoryVersion::class, 'history_version_id');
    }

    public function submission()
    {
        return $this->belongsTo(ScheduleSubmission::class, 'schedule_submission_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
