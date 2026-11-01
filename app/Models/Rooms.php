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

    /** Trims and collapses inner whitespace; a blank name means no building. */
    public static function normalizeBuilding(mixed $building): mixed
    {
        if (! is_string($building)) {
            return $building;
        }
        $name = trim((string) preg_replace('/\s+/', ' ', $building));

        return $name === '' ? null : $name;
    }

    /** Names that differ only in case or spacing are the same building. */
    public static function buildingKey(?string $building): string
    {
        return mb_strtolower((string) self::normalizeBuilding($building));
    }

    /**
     * Reuses the spelling already on file for this building so case or spacing
     * variants never split one building into two.
     */
    public static function canonicalBuilding(mixed $building, ?int $ignoreRoomId = null): mixed
    {
        $name = self::normalizeBuilding($building);
        if (! is_string($name)) {
            return $name;
        }

        return self::query()
            ->whereNotNull('building')
            ->when($ignoreRoomId !== null, fn ($query) => $query->whereKeyNot($ignoreRoomId))
            ->pluck('building')
            ->first(fn ($existing) => self::buildingKey($existing) === self::buildingKey($name))
            ?? $name;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, self> */
    public static function inBuilding(string $building)
    {
        $key = self::buildingKey($building);

        return self::query()
            ->whereNotNull('building')
            ->get()
            ->filter(fn (self $room) => self::buildingKey($room->building) === $key)
            ->values();
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
