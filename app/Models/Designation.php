<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An administrative post an instructor may hold. Holding one deloads the
 * instructor by `deload_units`, which is subtracted from their maximum to give
 * the Basic Load the scheduler places against.
 *
 * A designation may sit under one other, one level deep: "Director" over
 * "Networking Dev't". A designation that has sub-designations is a heading --
 * it is the sub-designations that instructors hold.
 */
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

    /** The instructors holding this designation, through designation_faculty. */
    public function faculties()
    {
        return $this->belongsToMany(Faculty::class, 'designation_faculty')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * "Director · Networking Dev't" for a sub-designation, the bare name
     * otherwise -- how a held designation is shown and printed. Callers that
     * serialise many should eager-load `parent` to keep this to one query.
     */
    public function getLabelAttribute(): string
    {
        $parentName = $this->parent_id === null ? null : $this->parent?->name;

        return $parentName === null ? (string) $this->name : "{$parentName} · {$this->name}";
    }

    /**
     * The display order the roster and every designation picker uses: the
     * explicit sort_order first, then alphabetically so two designations left
     * at the default 0 still come out in a stable order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
