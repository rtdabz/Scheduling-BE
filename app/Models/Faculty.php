<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use SoftDeletes;

    public const NAME_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

    protected $table = 'faculties';

    protected $fillable = [
        'user_id',
        'administrative_role',
        'first_name',
        'last_name',
        'middle_name',
        'suffix',
        'employment_type',
        'max_units',
        'overload_units',
        'deload_units',
        'department_id',
        'program_id',
        'status',
        'profile_picture',
    ];

    public function designations()
    {
        return $this->belongsToMany(Designation::class, 'designation_faculty')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

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
