<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    protected $table = 'curricula';

    protected $fillable = ['department_id', 'program_id', 'code', 'effective_school_year', 'status'];

    public function department() {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function courses() {
        return $this->belongsToMany(Course::class, 'curriculum_subject')
            ->withPivot(['year_level', 'semester'])
            ->withTimestamps();
    }
}
