<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An administrative post an instructor may hold. Holding one deloads the
 * instructor by `deload_units`, which is subtracted from their maximum to give
 * the Basic Load the scheduler places against.
 */
class Designation extends Model
{
    use SoftDeletes;

    protected $table = 'designations';

    protected $fillable = [
        'name',
        'code',
        'deload_units',
        'description',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'deload_units' => 'integer',
        'sort_order' => 'integer',
    ];

    public function faculties()
    {
        return $this->hasMany(Faculty::class, 'designation_id');
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
