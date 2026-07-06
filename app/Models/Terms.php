<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Terms extends Model
{
    protected $table = 'terms';
    protected $fillable = [
        'academic_year',
        'semester',
        'is_active',
        'is_enabled',
    ];
    public static function boot()
    {
        parent::boot();

        static::saving(function ($term) {
            if ($term->is_active) {
                static::where('id', '!=', $term->id)
                      ->where('is_active', true)
                      ->update(['is_active' => false]);
            }
        });
    }
}
