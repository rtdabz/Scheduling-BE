<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sections extends Model
{
    use SoftDeletes;

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
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function term()
    {
        return $this->belongsTo(Terms::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'section_id');
    }
}
