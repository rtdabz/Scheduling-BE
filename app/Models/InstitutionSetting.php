<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstitutionSetting extends Model
{
    protected $table = 'institution_settings';

    protected $fillable = [
        'president_name',
        'president_title',
    ];

    /**
     * The single settings row, created on demand so a fresh database (or one
     * migrated before this table existed) still answers.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'president_name' => 'ATTY. NADYA B. EMANO-ELIPE',
            'president_title' => 'OIC-College President',
        ]);
    }
}
