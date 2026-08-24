<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleHistory extends Model
{
    protected $fillable = [
        'schedule_id', 'term_id', 'section_id', 'course_id', 'department_id',
        'actor_user_id', 'action', 'snapshot', 'changes',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'changes' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function section()
    {
        return $this->belongsTo(Sections::class, 'section_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
