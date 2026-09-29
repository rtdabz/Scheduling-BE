<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleSplit extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'schedule_splits';

    protected $fillable = [
        'schedule_id',
        'split_group_id',
        'meeting_type',
        'meeting_index',
    ];

    public function schedule()
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }
}
