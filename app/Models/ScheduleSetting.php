<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleSetting extends Model
{
    protected $table = 'schedule_settings';

    protected $fillable = [
        'opening_time',
        'closing_time',
        'slot_interval'
    ];
}
