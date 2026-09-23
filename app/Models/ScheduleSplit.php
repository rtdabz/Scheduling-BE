<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleSplit extends Model
{
    use HasFactory, MassPrunable, SoftDeletes;

    /** Retired splits follow the same retention as Schedule::prunable(). */
    public function prunable(): Builder
    {
        return static::onlyTrashed()->where('deleted_at', '<', now()->subMonths((int) config('app.schedule_archive_retention_months', 12)));
    }

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
