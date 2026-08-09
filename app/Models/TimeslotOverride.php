<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeslotOverride extends Model
{
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
