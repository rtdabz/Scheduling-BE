<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A course's teaching delegation in one academic semester. */
class CourseTeachingAssignment extends Model
{
    protected $fillable = [
        'course_id', 'semester_id', 'teaching_department_id', 'teaching_program_id',
        'teaching_source_program_id', 'is_legacy_snapshot',
    ];

    protected $casts = ['is_legacy_snapshot' => 'boolean'];
}
