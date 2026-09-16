<?php

namespace App\Http\Requests\Timeslot;

use Illuminate\Foundation\Http\FormRequest;

abstract class TimeslotRequest extends FormRequest
{
    /** A 12-hour clock time such as "7:30 AM". */
    public const TIME_FORMAT_RULE = 'regex:/^(0?[1-9]|1[0-2]):[0-5][0-9]\s?(AM|PM)$/i';

    /** Access is enforced by the route's middleware. */
    public function authorize(): bool
    {
        return true;
    }
}
