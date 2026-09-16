<?php

namespace App\Http\Requests\Timeslot;

class StoreTimeslotOverrideRequest extends TimeslotRequest
{
    /** Every field is required on create and optional on update. */
    protected string $presence = 'required';

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'duration_minutes' => [$this->presence, 'integer', 'min:1', 'max:720'],
            'start_time' => [$this->presence, 'string', self::TIME_FORMAT_RULE],
            'is_active' => [$this->presence, 'boolean'],
        ];
    }
}
