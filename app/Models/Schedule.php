<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $table = 'schedules';

    protected $fillable = [
        'term_id',
        'section_id',
        'subject_id',
        'faculty_id',
        'room_id',
        'department_id',
        'day',
        'start_time',
        'end_time',
        'status',
        'rejection_reason',
        'reviewed_by_dean',
        'reviewed_at_dean',
        'approved_by_vpaa',
        'approved_at_vpaa',
    ];

    public function term()
    {
        return $this->belongsTo(Terms::class);
    }

    public function section()
    {
        return $this->belongsTo(Sections::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subjects::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function room()
    {
        return $this->belongsTo(Rooms::class);
    }

    public function department()
    {
        return $this->belongsTo(Departments::class);
    }

    public function deanReviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_dean');
    }

    public function vpaaApprover()
    {
        return $this->belongsTo(User::class, 'approved_by_vpaa');
    }
}