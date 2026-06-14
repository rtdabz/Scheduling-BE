<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subjects extends Model
{
    protected $table = 'subjects';
    protected $fillable = [
        'subject_code',
        'subject_name',
        'lecture_hours',
        'lab_hours',
        'units',
        'subject_category',
        'year_level',
        'semester',
        'department_id',
        'status',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class);
    }
}
