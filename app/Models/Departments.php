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
        'scheduling_profile',
        'logo',
        'lecture_lab_schedule_override_enabled',
        'custom_lab_duration_override_enabled',
        'custom_lab_duration_minutes',
        'custom_lab_duration_6_hours_enabled',
        'custom_lab_duration_5_hours_enabled',
        'custom_lab_duration_other_enabled',
        'gec_split_schedule_override_enabled',
        'major_lecture_split_schedule_override_enabled',
    ];

    protected $casts = [
        'lecture_lab_schedule_override_enabled' => 'boolean',
        'custom_lab_duration_override_enabled' => 'boolean',
        'custom_lab_duration_minutes' => 'integer',
        'custom_lab_duration_6_hours_enabled' => 'boolean',
        'custom_lab_duration_5_hours_enabled' => 'boolean',
        'custom_lab_duration_other_enabled' => 'boolean',
        'gec_split_schedule_override_enabled' => 'boolean',
        'major_lecture_split_schedule_override_enabled' => 'boolean',
        // Deliberately not fillable: only the department secretary changes it,
        // through SchedulingSettingsController.
        'sunday_classes_enabled' => 'boolean',
        // Also not fillable: set by the secretary through ProgramRoomController.
        'room_sharing_policy' => 'string',
        'scheduling_profile' => 'string',
    ];

    /** Rooms are one pool shared by every program (the default). */
    public const ROOM_SHARING_OPEN = 'open';

    /** Home room, then a shared room, then another program's vacant room as a suggestion. */
    public const ROOM_SHARING_HOME_FIRST = 'home_first';

    /** Home rooms and shared rooms only. */
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
