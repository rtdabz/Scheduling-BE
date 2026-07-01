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
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function term()
    {
        return $this->belongsTo(Terms::class, 'term_id');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'section_id');
    }
}
