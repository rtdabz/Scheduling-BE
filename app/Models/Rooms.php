<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rooms extends Model
{
    protected $table = 'rooms';
    protected $fillable = [
        'room_code',
        'room_name',
        'room_type',
        'status',
        'department_id',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }
}
