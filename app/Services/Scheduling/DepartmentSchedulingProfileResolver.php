<?php

namespace App\Services\Scheduling;

use App\Enums\DepartmentSchedulingProfile;
use App\Models\Departments;

class DepartmentSchedulingProfileResolver
{
    public function resolve(Departments|int $department): DepartmentSchedulingProfile
    {
        $model = $department instanceof Departments
            ? $department
            : Departments::query()->findOrFail($department);

        return DepartmentSchedulingProfile::tryFrom((string) $model->scheduling_profile)
            ?? DepartmentSchedulingProfile::STANDARD;
    }
}
