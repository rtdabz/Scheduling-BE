<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    protected $table = 'curricula';

    protected $fillable = ['name', 'department_id', 'program_id', 'code', 'curriculum_version', 'academic_year', 'effective_school_year', 'status', 'description'];

    public function department() {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function courses() {
        return $this->belongsToMany(Course::class, 'curriculum_course')
            ->withPivot(['year_level', 'semester'])
            ->withTimestamps();
    }

    // Alias for subjects to support legacy calls/tests
    public function subjects() {
        return $this->courses();
    }
}
