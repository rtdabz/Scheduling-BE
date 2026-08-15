<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faculty extends Model
{
    protected $table = 'faculties';
    protected $fillable = [
        'first_name',
        'last_name',
        'middle_name',
        'employment_type',
        'max_units',
        'overload_units',
        'deload_units',
        'probono_units',
        'department_id',
        'status',
        'profile_picture',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function availabilities()
    {
        return $this->hasMany(FacultyAvailability::class, 'faculty_id');
    }
}
