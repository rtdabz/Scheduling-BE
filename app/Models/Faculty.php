<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use SoftDeletes;

    /** Name suffixes instructors and accounts accept; the forms offer exactly these. */
    public const NAME_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

    protected $table = 'faculties';

    protected $fillable = [
        'user_id',
        'administrative_role',
        'designation_id',
        'first_name',
        'last_name',
        'middle_name',
        'suffix',
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

    /**
     * The instructor's primary designation -- the first of `designations`, kept
     * in step by FacultyDesignationService for readers that want just one.
     */
    public function designation()
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    /** Every designation the instructor holds (up to three), in their listed order. */
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
