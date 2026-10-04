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

    protected $casts = [
        'allow_lecture_usage' => 'boolean',
        'home_program_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Rooms $room): void {
            if ($room->home_program_id === null) {
                return;
            }

            if ($room->isDirty('department_id') || self::isSharedType($room->room_type)) {
                $room->home_program_id = null;
            }
        });
    }

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
