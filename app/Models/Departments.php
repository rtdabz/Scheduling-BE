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
        'split_units_schedule_override_enabled',
        'custom_lab_duration_override_enabled',
        'custom_lab_duration_minutes',
        'custom_lab_duration_6_hours_enabled',
        'custom_lab_duration_5_hours_enabled',
        'custom_lab_duration_other_enabled',
        'gec_split_schedule_override_enabled',
        'force_schedule_reuse_enabled',
        'field_evening_schedule_enabled',
        'sunday_online_only_enabled',
        'online_slot_limit',
        'field_slot_limit',
    ];

    protected $casts = [
        'lecture_lab_schedule_override_enabled' => 'boolean',
        'split_units_schedule_override_enabled' => 'boolean',
        'custom_lab_duration_override_enabled' => 'boolean',
        'custom_lab_duration_minutes' => 'integer',
        'custom_lab_duration_6_hours_enabled' => 'boolean',
        'custom_lab_duration_5_hours_enabled' => 'boolean',
        'custom_lab_duration_other_enabled' => 'boolean',
        'gec_split_schedule_override_enabled' => 'boolean',
        'force_schedule_reuse_enabled' => 'boolean',
        'field_evening_schedule_enabled' => 'boolean',
        'sunday_online_only_enabled' => 'boolean',
        'online_slot_limit' => 'integer',
        'field_slot_limit' => 'integer',
        'scheduling_profile' => 'string',
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
