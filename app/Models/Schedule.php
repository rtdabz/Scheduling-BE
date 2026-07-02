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
        'rejection_reason',      // ← missing
        'reviewed_by_dean',      // ← missing
        'reviewed_at_dean',      // ← missing
        'approved_by_vpaa',      // ← missing
        'approved_at_vpaa',      // ← missing
    ];

    public function term() {
        return $this->belongsTo(Term::class); 
    }
    
    public function section() {
        return $this->belongsTo(Section::class); 
    }

    public function subject() {
        return $this->belongsTo(Subject::class); 
    }

    public function faculty() {
        return $this->belongsTo(Faculty::class); 
    }

    public function room() { 
        return $this->belongsTo(Room::class); 
    }

    public function department() { 
        return $this->belongsTo(Department::class); 
    }

    // ← missing relationships
    public function deanReviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_dean');
    }

    public function vpaaApprover()
    {
        return $this->belongsTo(User::class, 'approved_by_vpaa');
    }
}