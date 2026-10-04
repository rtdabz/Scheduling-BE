<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Course extends Model
{
    use SoftDeletes;

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
        'teaching_source_program_id',
        'program_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $course): void {
            if ($course->wasChanged('course_code')) {
                SchedulingPolicy::clearFieldCourseCache();
            }
        });
    }

    /**
     * @param  iterable<int>|null  $courseIds  null for every placed course
     * @return array<int, array{year_level: string, semester: string}>
     */
    public static function curriculumPlacements(?iterable $courseIds = null): array
    {
        $rows = DB::table('curriculum_course')
            ->join('curriculum', 'curriculum.id', '=', 'curriculum_course.curriculum_id')
            ->where('curriculum.status', 'active')
            ->when($courseIds !== null, fn ($query) => $query->whereIn('curriculum_course.course_id', collect($courseIds)->map(static fn ($id): int => (int) $id)->all()))
            ->orderByDesc('curriculum.effective_school_year')
            ->orderByDesc('curriculum_course.curriculum_id')
            ->get(['curriculum_course.course_id', 'curriculum_course.year_level', 'curriculum_course.semester']);

        $placements = [];
        foreach ($rows as $row) {
            $placements[(int) $row->course_id] ??= [
                'year_level' => (string) $row->year_level,
                'semester' => match ((int) $row->semester) {
                    1 => '1st',
                    2 => '2nd',
                    default => 'summer',
                },
            ];
        }

        return $placements;
    }

    /** @param  iterable<int>  $courseIds */
    public static function syncPlacementFromCurricula(iterable $courseIds): void
    {
        $courseIds = collect($courseIds)->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($courseIds === []) {
            return;
        }

        foreach (static::curriculumPlacements($courseIds) as $courseId => $placement) {
            DB::table('courses')
                ->where('id', $courseId)
                ->where(fn ($query) => $query
                    ->where('year_level', '!=', $placement['year_level'])
                    ->orWhere('semester', '!=', $placement['semester']))
                ->update($placement);
        }
    }


    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function teachingDepartment()
    {
        return $this->belongsTo(Departments::class, 'teaching_department_id');
    }

    public function teachingProgram()
    {
        return $this->belongsTo(Program::class, 'teaching_program_id');
    }

    public function teachingSourceProgram()
    {
        return $this->belongsTo(Program::class, 'teaching_source_program_id');
    }

    public function scopeDelegatedTo($query, int $departmentId, ?int $programId = null)
    {
        return $query->where('teaching_department_id', $departmentId)->where(fn ($kind) => $kind
            ->where(fn ($crossCollege) => $crossCollege
                ->where(fn ($owner) => $owner->whereNull('department_id')->orWhere('department_id', '!=', $departmentId))
                ->when($programId !== null, fn ($scope) => $scope->where(fn ($program) => $program
                    ->where('program_id', $programId)
                    ->orWhere('teaching_program_id', $programId))))
            ->orWhere(fn ($sibling) => $sibling
                ->where('department_id', $departmentId)
                ->whereNotNull('teaching_program_id')
                ->where(fn ($owner) => $owner->whereNull('program_id')->orWhereColumn('program_id', '!=', 'teaching_program_id'))
                ->when($programId !== null, fn ($scope) => $scope->where('teaching_program_id', $programId))));
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
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
