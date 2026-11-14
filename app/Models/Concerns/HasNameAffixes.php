<?php

namespace App\Models\Concerns;

use App\Support\PersonName;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Normalizes the optional title and credentials on every save path.
 */
trait HasNameAffixes
{
    protected function honorificPrefix(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => PersonName::clean($value));
    }

    protected function credentials(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => PersonName::cleanCredentials($value));
    }
}
