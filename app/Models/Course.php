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
        'program_id',
        'status',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $course): void {
            if ($course->wasChanged('course_code')) {
                $course->renameFieldCourseSettings((string) $course->getOriginal('course_code'));
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

    /**
     * field_course_settings refers to courses by code, so a renamed code would
     * silently switch its field setting off. Carry the setting over to the new
     * code, for the owning department only, or, for a shared course, for every
     * department that does not own a course of its own under the old code.
     */
    private function renameFieldCourseSettings(string $oldCode): void
    {
        $oldCode = SchedulingPolicy::normalizeCourseCode($oldCode);
        $newCode = SchedulingPolicy::normalizeCourseCode((string) $this->course_code);
        if ($oldCode === $newCode) {
            return;
        }

        $rows = DB::table('field_course_settings')
            ->where('course_code', $oldCode)
            ->when(
                $this->department_id !== null,
                fn ($query) => $query->where('department_id', $this->department_id),
                fn ($query) => $query->where(fn ($scope) => $scope
                    ->whereNull('department_id')
                    ->orWhereNotIn('department_id', static::query()
                        ->where('course_code', $oldCode)
                        ->whereNotNull('department_id')
                        ->select('department_id'))),
            )
            ->get(['id', 'department_id']);

        foreach ($rows as $row) {
            $alreadySet = DB::table('field_course_settings')
                ->where('course_code', $newCode)
                ->where('department_id', $row->department_id)
                ->exists();

            $alreadySet
                ? DB::table('field_course_settings')->where('id', $row->id)->delete()
                : DB::table('field_course_settings')->where('id', $row->id)->update(['course_code' => $newCode, 'updated_at' => now()]);
        }

        if ($rows->isNotEmpty()) {
            SchedulingPolicy::clearFieldCourseCache();
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
