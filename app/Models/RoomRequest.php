<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'room_id',
        'semester_id',
        'requesting_department_id',
        'owner_department_id',
        'status',
        'purpose',
        'review_remarks',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(Rooms::class, 'room_id')->withTrashed();
    }

    public function academicSemester()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function requestingDepartment()
    {
        return $this->belongsTo(Departments::class, 'requesting_department_id');
    }

    public function ownerDepartment()
    {
        return $this->belongsTo(Departments::class, 'owner_department_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function windows()
    {
        return $this->hasMany(RoomRequestWindow::class)->orderBy('day')->orderBy('start_time');
    }
}
