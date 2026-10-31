<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumClassificationRule extends Model
{
    protected $table = 'curriculum_classification_rules';

    protected $fillable = ['department_id', 'match_kind', 'match_value', 'category', 'enabled'];

    protected $casts = [
        'department_id' => 'integer',
        'enabled' => 'boolean',
    ];

    public const KIND_EXACT = 'exact';

    public const KIND_PREFIX = 'prefix';
}
