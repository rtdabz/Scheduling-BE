<?php

namespace App\Http\Requests\Designation;

use App\Models\Designation;

class UpdateDesignationRequest extends DesignationRequest
{
    protected function existing(): ?Designation
    {
        return $this->route('designation');
    }
}
