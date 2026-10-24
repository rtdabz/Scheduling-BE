<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomRecoveryPlan extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'applied_groups' => 'array', 'selected_groups' => 'array',
        'expires_at' => 'datetime', 'applied_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
        'dean_reviewed_at' => 'datetime'];

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
