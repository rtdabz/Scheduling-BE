<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomRecoveryCase extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['original_rows' => 'array'];

    public function room()
    {
        return $this->belongsTo(Rooms::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }
}
