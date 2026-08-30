<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'department_id',
        'cluster',
        'code',
        'name',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'program_id');
    }

    public function faculties(): HasMany
    {
        return $this->hasMany(Faculty::class, 'program_id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'program_id');
    }
}
