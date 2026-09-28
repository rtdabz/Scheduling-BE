<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The single global settings row: the signatory and the operating hours. */
class InstitutionSetting extends Model
{
    protected $table = 'institution_settings';

    protected $fillable = [
        'president_name',
        'president_title',
        'opening_time',
        'closing_time',
        'field_end_time',
        'slot_interval',
    ];

    /**
     * The single settings row, created on demand so a fresh database (or one
     * migrated before this table existed) still answers.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'president_name' => 'College President',
            'president_title' => 'President',
            'opening_time' => '07:00:00',
            'closing_time' => '20:30:00',
            'field_end_time' => '17:00:00',
            'slot_interval' => 30,
        ]);
    }
}
