<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleSubmission extends Model
{
    protected $fillable = [
        'department_id',
        'term_id',
        'parent_submission_id',
        'revision_number',
        'status',
        'submitted_by',
        'submitted_at',
        'dean_reviewed_by',
        'dean_reviewed_at',
        'vpaa_reviewed_by',
        'vpaa_reviewed_at',
        'withdrawn_by',
        'withdrawn_at',
        'rejection_reason',
        'approval_override',
        'approval_override_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'dean_reviewed_at' => 'datetime',
        'vpaa_reviewed_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'approval_override' => 'boolean',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class);
    }

    public function term()
    {
        return $this->belongsTo(Terms::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_submission_id');
    }

    public function sections()
    {
        return $this->belongsToMany(
            Sections::class,
            'schedule_submission_sections',
            'schedule_submission_id',
            'section_id',
        )
            ->withPivot('state')
            ->withTimestamps();
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function deanReviewer()
    {
        return $this->belongsTo(User::class, 'dean_reviewed_by');
    }

    public function vpaaReviewer()
    {
        return $this->belongsTo(User::class, 'vpaa_reviewed_by');
    }

    public function withdrawer()
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function audits()
    {
        return $this->hasMany(SchedulingAuditLog::class);
    }
}
