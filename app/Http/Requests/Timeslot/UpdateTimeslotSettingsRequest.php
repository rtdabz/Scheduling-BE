<?php

namespace App\Http\Requests\Timeslot;

class UpdateTimeslotSettingsRequest extends TimeslotRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'opening_time' => ['required', 'string', self::TIME_FORMAT_RULE],
            'closing_time' => ['required', 'string', self::TIME_FORMAT_RULE],
            'slot_interval' => ['required', 'integer', 'min:1', 'max:720'],
        ];
    }
}
