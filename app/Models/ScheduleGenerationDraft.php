<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleGenerationDraft extends Model
{
    protected $fillable = ['user_id', 'department_id', 'semester_id', 'payload', 'revision'];

    protected $casts = [
        'payload' => 'array',
        'revision' => 'integer',
    ];
}
