<?php

namespace App\Models;

use App\Models\Concerns\PreservesApprovalActors;
use Illuminate\Database\Eloquent\Model;

class ScheduleGenerationRun extends Model
{
    use PreservesApprovalActors;

    public const RECORDED_ACTORS = ['requested_by' => null];

    public const REPEATABLE_ACTORS = [];

    protected $fillable = [
        'run_id', 'requested_by', 'semester_id', 'department_id', 'year_level',
        'status', 'result', 'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'actor_snapshots' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
