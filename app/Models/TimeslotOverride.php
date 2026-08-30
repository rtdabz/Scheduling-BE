<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimeslotOverride extends Model
{
    use SoftDeletes;

    protected $table = 'timeslot_override';

    protected $fillable = [
        'duration_minutes',
        'start_time',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
