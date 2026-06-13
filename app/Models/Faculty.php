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
        'department_id',
        'status',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class);
    }
}
