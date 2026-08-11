<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $table = 'courses';

    protected $fillable = [
        'course_code',
        'course_name',
        'lecture_hours',
        'lab_hours',
        'units',
        'course_category',
        'room_type_required',
        'year_level',
        'semester',
        'department_id',
        'status',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function teachingAssignment()
    {
        return $this->hasOne(CourseTeachingAssignment::class, 'course_id');
    }

    public function categories()
    {
        return $this->belongsToMany(CourseCategory::class, 'course_category_mapping', 'course_id', 'category_id')
            ->withTimestamps();
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'course_id');
    }
    public function curricula() {
        return $this->belongsToMany(Curriculum::class, 'curriculum_course')
            ->withPivot(['year_level', 'semester'])
            ->withTimestamps();
    }
}
