<?php

namespace App\Models;

use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores the day by name, like every other day column. `day_index`
 * (0 = Monday, SchedulingPolicy::DAYS order) is derived for the API and the
 * solver, which work on grid positions.
 */
class FacultyAvailability extends Model
{
    protected $table = 'faculty_availabilities';
    protected $fillable = [
        'faculty_id',
        'day',
        'day_index',
        'start_time',
        'end_time',
    ];

    public function getDayIndexAttribute(): int
    {
        return SchedulingPolicy::dayIndex((string) $this->day);
    }

    public function setDayIndexAttribute(int|string $index): void
    {
        $this->attributes['day'] = SchedulingPolicy::DAYS[(int) $index];
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }
}
