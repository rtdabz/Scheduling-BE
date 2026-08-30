<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use SoftDeletes;

    protected $table = 'faculties';

    protected $fillable = [
        'user_id',
        'administrative_role',
        'first_name',
        'last_name',
        'middle_name',
        'employment_type',
        'max_units',
        'overload_units',
        'deload_units',
        'probono_units',
        'department_id',
        'program_id',
        'status',
        'profile_picture',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function availabilities()
    {
        return $this->hasMany(FacultyAvailability::class, 'faculty_id');
    }
}
