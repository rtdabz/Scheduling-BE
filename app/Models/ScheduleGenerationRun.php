<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleGenerationRun extends Model
{
    protected $fillable = [
        'run_id', 'requested_by', 'term_id', 'department_id', 'year_level',
        'status', 'result', 'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
