<?php

namespace App\Models;

use App\Models\Concerns\HasNameAffixes;
use App\Support\PersonName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use HasNameAffixes, SoftDeletes;

    /** Columns needed to format an instructor's name; for explicit selects. */
    public const NAME_COLUMNS = ['first_name', 'middle_name', 'last_name', 'suffix', 'honorific_prefix', 'credentials'];

    public const NAME_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

    protected $table = 'faculties';

    protected $fillable = [
        'user_id',
        'administrative_role',
        'first_name',
        'last_name',
        'middle_name',
        'suffix',
        'honorific_prefix',
        'credentials',
        'employment_type',
        'max_units',
        'overload_units',
        'deload_units',
        'department_id',
        'program_id',
        'status',
        'profile_picture',
    ];

    protected static function booted(): void
    {
        // A department with exactly one program leaves no choice to make, so an
        // instructor without a program belongs to it (same rule as sections).
        // Without this, major courses of that program reject every such instructor.
        static::saving(function (Faculty $faculty): void {
            if ($faculty->program_id === null && $faculty->department_id !== null) {
                $faculty->program_id = self::soleProgramIdOf((int) $faculty->department_id);
            }
        });
    }

    /** The department's only program, or null when it has none or several. */
    public static function soleProgramIdOf(int $departmentId): ?int
    {
        $ids = Program::query()->where('department_id', $departmentId)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** "Dr. Juan P. Dela Cruz Jr., DM" */
    public function formattedName(): string
    {
        return PersonName::format(
            $this->honorific_prefix,
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
            $this->credentials,
        );
    }

    public function designations()
    {
        return $this->belongsToMany(Designation::class, 'designation_faculty')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function availabilities()
    {
        return $this->hasMany(FacultyAvailability::class, 'faculty_id');
    }

    /** Attributes added to payloads that drive availability checks and display. */
    public const AVAILABILITY_APPENDS = ['availability_mode', 'standard_availabilities'];

    /** "custom" when windows are recorded, otherwise "standard"; null when windows are not loaded. */
    public function getAvailabilityModeAttribute(): ?string
    {
        if (! $this->relationLoaded('availabilities')) {
            return null;
        }

        return $this->availabilities->isEmpty() ? 'standard' : 'custom';
    }

    /** @return list<array{day_index: int, start_time: string, end_time: string}> */
    public function getStandardAvailabilitiesAttribute(): array
    {
        return \App\Services\Scheduling\Support\SchedulingPolicy::standardFacultyHours($this->employment_type);
    }
}
