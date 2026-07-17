<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleRecommendation extends Model
{
    protected $table = 'schedule_recommendations';

    protected $fillable = [
        'term_id',
        'section_id',
        'department_id',
        'requested_by',
        'accepted_by',
        'rejected_by',
        'rank',
        'score',
        'status',
        'input_payload',
        'recommended_schedules',
        'rejection_reason',
        'accepted_at',
        'rejected_at',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'recommended_schedules' => 'array',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(Terms::class);
    }

    public function section()
    {
        return $this->belongsTo(Sections::class);
    }

    public function department()
    {
        return $this->belongsTo(Departments::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function accepter()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
