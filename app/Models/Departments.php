<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Departments extends Model
{
    use SoftDeletes;
    
    protected $table = 'departments';

    protected $fillable = [
        'department_name',
        'department_code',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function rooms()
    {
        return $this->hasMany(Rooms::class, 'department_id');
    }

    public function sections()
    {
        return $this->hasMany(Sections::class, 'department_id');
    }

    public function faculties()
    {
        return $this->hasMany(Faculty::class, 'department_id');
    }
}
