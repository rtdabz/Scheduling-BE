<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstructorAssignmentDraft extends Model
{
    protected $fillable = ['user_id', 'department_id', 'semester_id', 'context', 'payload', 'revision'];

    protected $casts = [
        'payload' => 'array',
        'revision' => 'integer',
    ];
}
