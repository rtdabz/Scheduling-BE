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
        'teaching_department_id',
        'teaching_program_id',
        'program_id',
        'status',
    ];

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    /**
     * The college delegated to teach this course, overriding the department that
     * owns it. Null for the common case — see
     * SchedulingPolicy::assignedTeachingDepartmentId for the fallback rule.
     */
    public function teachingDepartment()
    {
        return $this->belongsTo(Departments::class, 'teaching_department_id');
    }

    public function teachingProgram()
    {
        return $this->belongsTo(Program::class, 'teaching_program_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
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
    public function curriculum() {
        return $this->belongsToMany(Curriculum::class, 'curriculum_course')
            ->withPivot(['year_level', 'semester'])
            ->withTimestamps();
    }
}
