<?php

namespace App\Models;

use App\Support\ActorIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Schedule extends Model
{
    use SoftDeletes;

    protected $table = 'schedules';

    public const UNLOCKED_STATUSES = ['draft', 'completed', 'revision', 'rejected', 'rejected_by_dean'];

    public const PLOTTING_EDITABLE_STATUSES = self::UNLOCKED_STATUSES;

    /**
     * Schedules are never archived. Deleting a class removes it and every split row permanently;
     * Schedule History keeps its own snapshots and is not touched. Rows soft-deleted before this
     * rule stay hidden and cannot be restored.
     */
    public function deletePermanently(): void
    {
        ScheduleSplit::withTrashed()->where('schedule_id', $this->getKey())->forceDelete();
        $this->forceDelete();
    }

    public const RESPONSE_RELATIONS = [
        'academicSemester:id,academic_year,semester',
        'section:id,section_name,year_level,semester,department_id,program_id,semester_id',
        'section.program:id,code',
        'course:id,course_code,course_name,lecture_hours,lab_hours,units,course_category,room_type_required,year_level,semester,department_id,teaching_department_id,teaching_program_id,program_id',
        'faculty:id,first_name,last_name,middle_name,suffix,honorific_prefix,credentials,department_id,program_id',
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
        'delegation_returned_from_department_id',
        'delegation_returned_at',
        'section_teaching_department_id',
        'section_teaching_program_id',
        'section_teaching_assigned_at',
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
        'creator_identity' => 'array',
        'faculty_assignment_done' => 'boolean',
        'delegation_returned_from_department_id' => 'integer',
        'delegation_returned_at' => 'datetime',
        'section_teaching_department_id' => 'integer',
        'section_teaching_program_id' => 'integer',
        'section_teaching_assigned_at' => 'datetime',
        'displaced_at' => 'datetime',
    ];

    /*
     * Who teaches a class (see SchedulingPolicy::courseForRow): a class that was returned
     * (delegation_returned_from_department_id set) is detached from the course-level
     * delegation and follows its own section_teaching_* columns; any other class follows
     * courses.teaching_*. The scopes below are the SQL form of that rule.
     */

    /** Classes that still follow the course-level delegation (never returned). */
    public function scopeFollowsCourseDelegation($query)
    {
        return $query->whereNull('schedules.delegation_returned_from_department_id');
    }

    /** Classes the given department teaches through a delegation (course- or section-level). */
    public function scopeDelegatedTo($query, int $departmentId)
    {
        return $query->where(fn ($rows) => $rows
            ->where(fn ($course) => $course->followsCourseDelegation()
                ->whereHas('course', fn ($c) => $c->where('courses.teaching_department_id', $departmentId)))
            // Explicit NOT NULL keeps every branch strictly true/false, so callers may negate
            // these scopes (whereNot) without NULL comparisons silently dropping rows.
            ->orWhere(fn ($section) => $section->whereNotNull('schedules.delegation_returned_from_department_id')
                ->whereNotNull('schedules.section_teaching_department_id')
                ->where('schedules.section_teaching_department_id', $departmentId)));
    }

    /** Classes delegated to a department other than the given one. */
    public function scopeDelegatedToOtherThan($query, int $departmentId)
    {
        return $query->where(fn ($rows) => $rows
            ->where(fn ($course) => $course->followsCourseDelegation()
                ->whereHas('course', fn ($c) => $c->whereNotNull('courses.teaching_department_id')
                    ->where('courses.teaching_department_id', '!=', $departmentId)))
            ->orWhere(fn ($section) => $section->whereNotNull('schedules.delegation_returned_from_department_id')
                ->whereNotNull('schedules.section_teaching_department_id')
                ->where('schedules.section_teaching_department_id', '!=', $departmentId)));
    }

    /** Classes taught by a department other than the one that offers them. */
    public function scopeDelegatedAwayFromOwner($query)
    {
        return $query->where(fn ($rows) => $rows
            ->where(fn ($course) => $course->followsCourseDelegation()
                ->whereHas('course', fn ($c) => $c->whereNotNull('courses.teaching_department_id')
                    ->whereColumn('courses.teaching_department_id', '!=', 'schedules.department_id')))
            ->orWhere(fn ($section) => $section->whereNotNull('schedules.delegation_returned_from_department_id')
                ->whereNotNull('schedules.section_teaching_department_id')
                ->whereColumn('schedules.section_teaching_department_id', '!=', 'schedules.department_id')));
    }

    /** Classes handed to the given program, at course or section level. */
    public function scopeHandedToProgram($query, int $programId)
    {
        return $query->where(fn ($rows) => $rows
            ->where(fn ($course) => $course->followsCourseDelegation()
                ->whereHas('course', fn ($c) => $c->where('courses.teaching_program_id', $programId)))
            ->orWhere(fn ($section) => $section->whereNotNull('schedules.delegation_returned_from_department_id')
                ->whereNotNull('schedules.section_teaching_program_id')
                ->where('schedules.section_teaching_program_id', $programId)));
    }

    public function returnedFromDepartment()
    {
        return $this->belongsTo(Departments::class, 'delegation_returned_from_department_id');
    }

    public function sectionTeachingDepartment()
    {
        return $this->belongsTo(Departments::class, 'section_teaching_department_id');
    }

    public function sectionTeachingProgram()
    {
        return $this->belongsTo(Program::class, 'section_teaching_program_id');
    }

    /** A displaced face-to-face class meeting online until a reviewed room reassignment. */
    public function isTemporarilyOnline(): bool
    {
        return $this->displaced_room_id !== null;
    }

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
        // Stamped server-side (not fillable) so the "Prepared by" signatory cannot be spoofed by a client.
        static::creating(function (Schedule $schedule): void {
            $schedule->created_by ??= auth()->id();
            $schedule->creator_identity = ActorIdentity::snapshot($schedule->created_by ? User::withTrashed()->find($schedule->created_by) : null);
        });

        // Once a displaced class is face-to-face in a room again, it no longer needs reassignment.
        static::saving(function (Schedule $schedule): void {
            if ($schedule->displaced_room_id !== null && $schedule->mode === 'on-site' && $schedule->room_id !== null) {
                $schedule->displaced_room_id = null;
                $schedule->displaced_at = null;
            }
        });

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

    public function displacedRoom()
    {
        return $this->belongsTo(Rooms::class, 'displaced_room_id');
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
