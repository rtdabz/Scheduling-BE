<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class RoomRecoveryPlan extends Model
{
    use Prunable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'applied_groups' => 'array', 'selected_groups' => 'array',
        'expires_at' => 'datetime', 'applied_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
        'dean_reviewed_at' => 'datetime'];

    /** Unsubmitted previews are disposable once expired; submitted requests are kept as approval history. */
    public function prunable(): Builder
    {
        return static::query()->where('status', 'preview')->where('expires_at', '<', now()->subDay());
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }
}
