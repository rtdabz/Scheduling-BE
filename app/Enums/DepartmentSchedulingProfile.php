<?php

namespace App\Enums;

enum DepartmentSchedulingProfile: string
{
    case STANDARD = 'standard';
    case LABORATORY_ENABLED = 'laboratory_enabled';

    public function supportsLaboratoryComponents(): bool
    {
        return $this === self::LABORATORY_ENABLED;
    }
}
