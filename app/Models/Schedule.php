<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    use SoftDeletes;

    protected $table = 'schedules';

    protected $with = ['split'];

    protected $appends = [
        'split_group_id',
        'meeting_type',
        'meeting_index',
    ];

    protected $fillable = [
        'semester_id',
        'section_id',
        // Which curriculum this row was generated from. Recorded rather than
        // derived: curricula are editable and a section can be re-pointed later.
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

    protected $casts = ['faculty_assignment_done' => 'boolean'];

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
                $split = $schedule->split ?: new ScheduleSplit;
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

        // The split row carries this schedule's meeting-type metadata and means
        // nothing without it. The database cascade only fires on a hard delete,
        // so a soft delete has to be carried across or the split is left live
        // behind a deleted owner, where it still surfaces in the split listing.
        // This covers single-model deletes; bulk deletes go through the query
        // builder and fire no model events, so those call retireSplitsFor().
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
     * Soft-deletes the split rows belonging to the given schedules.
     *
     * Bulk soft deletes go through the query builder, which fires no model
     * events, so every such path has to retire the splits explicitly.
     *
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
