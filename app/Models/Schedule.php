<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    use SoftDeletes;

    protected $table = 'schedules';

    public const UNLOCKED_STATUSES = ['draft', 'completed', 'revision', 'rejected', 'rejected_by_dean'];

    public const RESPONSE_RELATIONS = [
        'academicSemester:id,academic_year,semester',
        'section:id,section_name,year_level,semester,department_id,program_id,semester_id',
        'section.program:id,code',
        'course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category,room_type_required,year_level,semester,department_id,teaching_department_id,teaching_program_id,program_id',
        'faculty:id,first_name,last_name,middle_name,department_id,program_id',
        'room:id,room_code,building,room_type,allow_lecture_usage,department_id',
        'department:id,department_name,department_code',
        'program',
    ];

    protected $with = ['split'];

    protected $appends = [
        'split_group_id',
        'meeting_type',
        'meeting_index',
    ];

    protected $fillable = [
        'semester_id',
        'section_id',
        'curriculum_id',
        'course_id',
        'faculty_id',
        'faculty_assignment_done',
        'room_id',
        'department_id',
        'program_id',
        'day',
        'start_time',
        'end_time',
        'mode',
        'is_hybrid',
        'preferred_pattern',
        'split_group_id',
        'meeting_type',
        'meeting_index',
        'status',
    ];

    protected $casts = [
        'faculty_assignment_done' => 'boolean',
    ];

    protected ?string $tempSplitGroupId = null;

    protected ?string $tempMeetingType = null;

    protected ?int $tempMeetingIndex = null;

    public function split()
    {
        return $this->hasOne(ScheduleSplit::class, 'schedule_id');
    }

    public function getSplitGroupIdAttribute(): ?string
    {
        return $this->relationLoaded('split') && $this->split
            ? $this->split->split_group_id
            : $this->tempSplitGroupId;
    }

    public function setSplitGroupIdAttribute(?string $value): void
    {
        $this->tempSplitGroupId = $value;
        if ($this->relationLoaded('split') && $this->split) {
            $this->split->split_group_id = $value;
        }
    }

    public function getMeetingTypeAttribute(): ?string
    {
        return $this->relationLoaded('split') && $this->split
            ? $this->split->meeting_type
            : $this->tempMeetingType;
    }

    public function setMeetingTypeAttribute(?string $value): void
    {
        $this->tempMeetingType = $value;
        if ($this->relationLoaded('split') && $this->split) {
            $this->split->meeting_type = $value;
        }
    }

    public function getMeetingIndexAttribute(): ?int
    {
        return $this->relationLoaded('split') && $this->split
            ? (int) $this->split->meeting_index
            : $this->tempMeetingIndex;
    }

    public function setMeetingIndexAttribute(?int $value): void
    {
        $this->tempMeetingIndex = $value;
        if ($this->relationLoaded('split') && $this->split) {
            $this->split->meeting_index = $value;
        }
    }

    protected static function booted()
    {
        static::saved(function (Schedule $schedule) {
            if ($schedule->tempSplitGroupId !== null || $schedule->tempMeetingType !== null || $schedule->tempMeetingIndex !== null) {
                $split = ($schedule->wasRecentlyCreated && ! $schedule->relationLoaded('split'))
                    ? new ScheduleSplit
                    : ($schedule->split ?: new ScheduleSplit);
                $split->schedule_id = $schedule->id;
                if ($schedule->tempSplitGroupId !== null) {
                    $split->split_group_id = $schedule->tempSplitGroupId;
                }
                if ($schedule->tempMeetingType !== null) {
                    $split->meeting_type = $schedule->tempMeetingType;
                }
                if ($schedule->tempMeetingIndex !== null) {
                    $split->meeting_index = $schedule->tempMeetingIndex;
                }
                $split->save();
                $schedule->setRelation('split', $split);
            }
        });

        static::deleted(function (Schedule $schedule): void {
            if ($schedule->isForceDeleting()) {
                return;
            }

            ScheduleSplit::query()->where('schedule_id', $schedule->id)->delete();
        });

        static::restored(function (Schedule $schedule): void {
            ScheduleSplit::withTrashed()->where('schedule_id', $schedule->id)->restore();
        });
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Builder|array<int, int>|\Illuminate\Support\Collection  $scheduleIds
     */
    public static function retireSplitsFor($scheduleIds): void
    {
        ScheduleSplit::query()->whereIn('schedule_id', $scheduleIds)->delete();
    }

    public function academicSemester()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function section()
    {
        return $this->belongsTo(Sections::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function room()
    {
        return $this->belongsTo(Rooms::class);
    }

    public function department()
    {
        return $this->belongsTo(Departments::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }
}
