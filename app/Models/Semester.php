<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Semester extends Model
{
    use SoftDeletes;

    protected $table = 'semesters';
    protected $fillable = [
        'academic_year',
        'semester',
        'is_active',
        'is_enabled',
    ];
    public static function boot()
    {
        parent::boot();

        static::saving(function ($semester) {
            if ($semester->is_active) {
                static::where('id', '!=', $semester->id)
                      ->where('is_active', true)
                      ->update(['is_active' => false]);
            }
        });
    }
}
