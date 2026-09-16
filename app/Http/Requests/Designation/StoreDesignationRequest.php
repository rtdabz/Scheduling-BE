<?php

namespace App\Http\Requests\Designation;

use App\Models\Designation;

class StoreDesignationRequest extends DesignationRequest
{
    protected function existing(): ?Designation
    {
        return null;
    }
}
