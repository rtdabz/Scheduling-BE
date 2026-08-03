<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacultyAvailability extends Model
{
    protected $table = 'faculty_availabilities';
    protected $fillable = [
        'faculty_id',
        'day_index',
        'start_time',
        'end_time',
    ];

    public function faculty()
    {
        return $this->belongsTo(Faculty::class, 'faculty_id');
    }
}
