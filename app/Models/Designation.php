<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designation extends Model
{
    use SoftDeletes;

    protected $table = 'designations';

    protected $fillable = [
        'parent_id',
        'name',
        'code',
        'deload_units',
        'description',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'deload_units' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $appends = ['label'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function faculties()
    {
        return $this->belongsToMany(Faculty::class, 'designation_faculty')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function getLabelAttribute(): string
    {
        $parentName = $this->parent_id === null ? null : $this->parent?->name;

        return $parentName === null ? (string) $this->name : "{$parentName} · {$this->name}";
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
