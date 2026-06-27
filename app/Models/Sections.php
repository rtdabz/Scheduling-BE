<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sections extends Model
{
    protected $fillable = [
        'section_name',
        'year_level',
        'semester',
        'number_of_students',
        'department_id',
        'term_id',
        'status',
    ];
    protected $table = 'sections';

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
}
