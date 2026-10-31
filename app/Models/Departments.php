<?php

namespace App\Models;

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
