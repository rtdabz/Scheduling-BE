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
}
