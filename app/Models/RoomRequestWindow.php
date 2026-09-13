<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomRequestWindow extends Model
{
    protected $fillable = [
        'room_request_id',
        'day',
        'start_time',
        'end_time',
    ];

    public function request()
    {
        return $this->belongsTo(RoomRequest::class, 'room_request_id');
    }
}
