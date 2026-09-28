<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rooms extends Model
{
    use SoftDeletes;

    protected $table = 'rooms';
    protected $fillable = [
        'room_code',
        'building',
        'room_type',
        'allow_lecture_usage',
        'status',
        'department_id',
    ];

    // home_program_id is deliberately not fillable: only the department
    // secretary sets it, through ProgramRoomController.
    protected $casts = [
        'allow_lecture_usage' => 'boolean',
        'home_program_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // A home program belongs to the room's department, and only a room one
        // class holds at a time is worth dividing. Moving the room to another
        // department, or turning it into a field or online room, drops it.
        static::saving(function (Rooms $room): void {
            if ($room->home_program_id === null) {
                return;
            }

            if ($room->isDirty('department_id') || self::isSharedType($room->room_type)) {
                $room->home_program_id = null;
            }
        });
    }

    /**
     * Room types any number of classes may share at once. Every other room -- a
     * lecture or laboratory room -- holds one class at a time.
     */
    public const SHARED_ROOM_TYPES = ['field', 'online'];

    public static function isSharedType(?string $roomType): bool
    {
        return in_array($roomType, self::SHARED_ROOM_TYPES, true);
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function homeProgram()
    {
        return $this->belongsTo(Program::class, 'home_program_id');
    }
}
