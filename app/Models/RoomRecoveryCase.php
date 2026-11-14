<?php

namespace App\Models;

use App\Models\Concerns\PreservesApprovalActors;
use Illuminate\Database\Eloquent\Model;

class RoomRecoveryCase extends Model
{
    use PreservesApprovalActors;

    public const RECORDED_ACTORS = ['created_by' => null];

    public const REPEATABLE_ACTORS = [];

    protected $guarded = ['id'];

    protected $casts = ['actor_snapshots' => 'array', 'original_rows' => 'array'];

    public function room()
    {
        return $this->belongsTo(Rooms::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }
}
