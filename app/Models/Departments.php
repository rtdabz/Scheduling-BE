<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Departments extends Model
{
    use SoftDeletes;

    protected $table = 'departments';

    protected $fillable = [
        'department_name',
        'department_code',
        'logo',
        'lecture_lab_schedule_override_enabled',
        'gec_split_schedule_override_enabled',
        'major_lecture_split_schedule_override_enabled',
        'lecture_minutes_per_unit',
        'laboratory_minutes_per_unit',
        'curriculum_import_settings_revision',
    ];

    protected $casts = [
        'lecture_lab_schedule_override_enabled' => 'boolean',
        'gec_split_schedule_override_enabled' => 'boolean',
        'major_lecture_split_schedule_override_enabled' => 'boolean',
        'sunday_classes_enabled' => 'boolean',
        'room_sharing_policy' => 'string',
        'lecture_minutes_per_unit' => 'integer',
        'laboratory_minutes_per_unit' => 'integer',
        'curriculum_import_settings_revision' => 'integer',
    ];

    public const ROOM_SHARING_OPEN = 'open';

    public const ROOM_SHARING_HOME_FIRST = 'home_first';

    public const ROOM_SHARING_STRICT = 'strict';

    public const ROOM_SHARING_POLICIES = [
        self::ROOM_SHARING_OPEN,
        self::ROOM_SHARING_HOME_FIRST,
        self::ROOM_SHARING_STRICT,
    ];

    /**
     * Logos are stored as base64 data URLs (20–45 KB each). Serializing them inline made every list that
     * eager-loads `department` carry one copy per row (e.g. /courses, /faculties, /schedule-history grew to
     * tens of MB). Reading `logo` therefore yields a short, versioned URL to DepartmentLogoController,
     * which the browser downloads once and caches. Use rawLogo() when the stored image itself is needed.
     */
    protected function logo(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::logoUrl($this->getKey(), $value),
        );
    }

    public function rawLogo(): ?string
    {
        $value = $this->getAttributes()['logo'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function logoUrl(mixed $departmentId, ?string $storedLogo): ?string
    {
        if ($storedLogo === null || $storedLogo === '') {
            return null;
        }
        // Only inline images are rewritten; a logo already stored as a URL is served as-is.
        if (! str_starts_with($storedLogo, 'data:')) {
            return $storedLogo;
        }
        if ($departmentId === null) {
            return null;
        }

        return url('/api/departments/'.$departmentId.'/logo').'?v='.self::logoVersion($storedLogo);
    }

    /**
     * Bumped when served logo responses change in a way browsers must not reuse from cache. Revision 2: every
     * response carries `Access-Control-Allow-Origin: *`; revision-1 copies cached by plain <img> tags lacked it
     * and broke the crossOrigin loads that PDF printing makes.
     */
    private const LOGO_URL_REVISION = '2';

    public static function logoVersion(string $storedLogo): string
    {
        return self::LOGO_URL_REVISION.hash('crc32b', $storedLogo).dechex(strlen($storedLogo));
    }

    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function programs()
    {
        return $this->hasMany(Program::class, 'department_id');
    }

    public function rooms()
    {
        return $this->hasMany(Rooms::class, 'department_id');
    }

    public function sections()
    {
        return $this->hasMany(Sections::class, 'department_id');
    }

    public function faculties()
    {
        return $this->hasMany(Faculty::class, 'department_id');
    }
}
