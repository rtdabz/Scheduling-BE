<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sections extends Model
{
    protected $fillable = [
        'section_name',
        'year_level',
        'semester',
        'department_id',
        'term_id',
        'status',
    ];
    protected $table = 'sections';

    public function department()
    {
        return $this->belongsTo(Departments::class);
    }

    public function term()
    {
        return $this->belongsTo(Terms::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
}
