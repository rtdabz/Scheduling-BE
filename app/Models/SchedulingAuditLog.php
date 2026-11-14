<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Support\ActorIdentity;
use Illuminate\Database\Eloquent\Model;

class SchedulingAuditLog extends Model
{
    use AppendOnly;

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            // Event time comes from the application clock (APP_TIMEZONE), never the DB server's CURRENT_TIMESTAMP default.
            $log->created_at ??= now();
            ActorIdentity::capture($log, 'user_id');
        });
    }

    public function getRecordedActorAttribute(): ?array
    {
        return ActorIdentity::recorded($this->metadata, $this->user_id);
    }

    public $timestamps = false;

    protected $table = 'scheduling_audit_logs';

    protected $fillable = [
        'user_id',
        'schedule_recommendation_id',
        'history_version_id',
        'schedule_submission_id',
        'semester_id',
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
