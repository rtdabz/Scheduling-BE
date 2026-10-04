<?php

namespace App\Http\Requests\Timeslot;

use Illuminate\Foundation\Http\FormRequest;

abstract class TimeslotRequest extends FormRequest
{
    public const TIME_FORMAT_RULE = 'regex:/^(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM)$/i';

    public function authorize(): bool
    {
        return true;
    }
}
