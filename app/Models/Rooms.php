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
    ];

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
}
