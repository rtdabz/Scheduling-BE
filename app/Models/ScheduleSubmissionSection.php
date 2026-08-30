<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleSubmissionSection extends Model
{
    protected $fillable = [
        'schedule_submission_id',
        'section_id',
        'state',
    ];

    public function submission()
    {
        return $this->belongsTo(ScheduleSubmission::class, 'schedule_submission_id');
    }

    public function section()
    {
        return $this->belongsTo(Sections::class);
    }
}
