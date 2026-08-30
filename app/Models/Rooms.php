<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rooms extends Model
{
    use SoftDeletes;

    protected $table = 'rooms';
    protected $fillable = [
        'room_code',
        'building',
        'room_type',
        'allow_lecture_usage',
        'status',
        'max_concurrent_classes',
        'department_id',
    ];

    protected $casts = [
        'allow_lecture_usage' => 'boolean',
        'max_concurrent_classes' => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }
}
