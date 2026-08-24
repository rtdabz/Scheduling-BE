<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    protected $table = 'curriculum';

    protected $fillable = ['name', 'department_id', 'program_id', 'code', 'effective_school_year', 'status', 'description'];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($curriculum) {
            if ($curriculum->status === 'active' && $curriculum->department_id) {
                \DB::transaction(function () use ($curriculum) {
                    self::where('department_id', $curriculum->department_id)
                        ->where('id', '!=', $curriculum->id)
                        ->when($curriculum->program_id === null,
                            fn ($query) => $query->whereNull('program_id'),
                            fn ($query) => $query->where('program_id', $curriculum->program_id),
                        )
                        ->where('status', 'active')
                        ->update(['status' => 'draft']);
                });
            }
        });
    }

    public function department() {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function courses() {
        return $this->belongsToMany(Course::class, 'curriculum_course', 'curriculum_id', 'course_id')
            ->withPivot(['year_level', 'semester'])
            ->withTimestamps();
    }

    // Alias for subjects to support legacy calls/tests
    public function subjects() {
        return $this->courses();
    }
}
