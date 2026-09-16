<?php

namespace App\Http\Requests\Timeslot;

class UpdateTimeslotOverrideRequest extends StoreTimeslotOverrideRequest
{
    protected string $presence = 'sometimes';
}
