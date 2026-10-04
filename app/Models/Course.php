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
            // Field settings follow the course id; only the cached codes go stale.
            if ($course->wasChanged('course_code')) {
                SchedulingPolicy::clearFieldCourseCache();
            }
        });
    }

    /**
     * The curriculum is the source of a course's year level and semester. The
     * copy on the course row mirrors the newest active curriculum that places
     * it, the same rule the course list displays; a course no active curriculum
     * places keeps its catalogue value.
     *
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

    /** The program that handed this course to its teaching program, when recorded. */
    public function teachingSourceProgram()
    {
        return $this->belongsTo(Program::class, 'teaching_source_program_id');
    }

    /**
     * Courses handed to this department (and, for a Program Head, this program)
     * to teach for someone else. Two kinds:
     *  - another college's course, delegated to this college (IT's GEC 101 → CAS);
     *  - a course of this college handed to a sibling program (BSED-ENG Prof Ed →
     *    BEED), which never leaves the college, so "owner is another college"
     *    alone would miss it.
     */
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
