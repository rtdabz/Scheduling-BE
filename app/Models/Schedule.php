<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

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
        'term_id',
        'section_id',
        'course_id',
        'faculty_id',
        'faculty_assignment_done',
        'room_id',
        'department_id',
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
            $version = ScheduleHistoryVersion::create([
                'schedule_id' => $schedule->id,
                'term_id' => $schedule->term_id,
                'section_id' => $schedule->section_id,
                'course_id' => $schedule->course_id,
                'department_id' => $schedule->department_id,
                'actor_user_id' => Auth::id(),
                'action' => $schedule->wasRecentlyCreated ? 'created' : 'updated',
                'snapshot' => $schedule->getAttributes(),
                'changes' => $schedule->getChanges(),
            ]);
            ScheduleHistoryItem::create(['history_version_id' => $version->id, 'original_schedule_id' => $schedule->id, 'section_id' => $schedule->section_id, 'course_id' => $schedule->course_id, 'faculty_id' => $schedule->faculty_id, 'room_id' => $schedule->room_id, 'after_snapshot' => $schedule->getAttributes(), 'snapshot_metadata' => ['event' => 'saved']]);
        });

        static::deleted(function (Schedule $schedule) {
            $version = ScheduleHistoryVersion::create([
                'schedule_id' => $schedule->id,
                'term_id' => $schedule->term_id,
                'section_id' => $schedule->section_id,
                'course_id' => $schedule->course_id,
                'department_id' => $schedule->department_id,
                'actor_user_id' => Auth::id(),
                'action' => $schedule->isForceDeleting() ? 'deleted' : 'archived',
                'snapshot' => $schedule->getAttributes(),
            ]);
            ScheduleHistoryItem::create(['history_version_id' => $version->id, 'original_schedule_id' => $schedule->id, 'section_id' => $schedule->section_id, 'course_id' => $schedule->course_id, 'faculty_id' => $schedule->faculty_id, 'room_id' => $schedule->room_id, 'before_snapshot' => $schedule->getAttributes(), 'snapshot_metadata' => ['event' => 'deleted']]);
        });

        static::restored(function (Schedule $schedule) {
            $version = ScheduleHistoryVersion::create([
                'schedule_id' => $schedule->id,
                'term_id' => $schedule->term_id,
                'section_id' => $schedule->section_id,
                'course_id' => $schedule->course_id,
                'department_id' => $schedule->department_id,
                'actor_user_id' => Auth::id(),
                'action' => 'restored',
                'snapshot' => $schedule->getAttributes(),
            ]);
            ScheduleHistoryItem::create(['history_version_id' => $version->id, 'original_schedule_id' => $schedule->id, 'section_id' => $schedule->section_id, 'course_id' => $schedule->course_id, 'faculty_id' => $schedule->faculty_id, 'room_id' => $schedule->room_id, 'after_snapshot' => $schedule->getAttributes(), 'snapshot_metadata' => ['event' => 'restored']]);
        });

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
    }

    public function term()
    {
        return $this->belongsTo(Terms::class);
    }

    public function section()
    {
        return $this->belongsTo(Sections::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
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
}
