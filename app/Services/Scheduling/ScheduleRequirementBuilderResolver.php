<?php

namespace App\Services\Scheduling;

use App\Enums\DepartmentSchedulingProfile;
use App\Models\Course;
use App\Models\Departments;
use App\Models\Sections;

class ScheduleRequirementBuilderResolver
{
    public function __construct(
        private readonly DepartmentSchedulingProfileResolver $profiles,
        private readonly StandardScheduleRequirementBuilder $standard,
        private readonly LaboratoryScheduleRequirementBuilder $laboratory,
    ) {}

    /** @return array<int, list<array<string, mixed>>> */
    public function build(Sections $section, array $courseIds, array $options = []): array
    {
        $department = $section->department ?: Departments::query()->findOrFail((int) $section->department_id);
        $courses = Course::query()
            ->with('categories')
            ->whereIn('id', array_map('intval', $courseIds))
            ->get();
        $builder = $this->profiles->resolve($department) === DepartmentSchedulingProfile::LABORATORY_ENABLED
            ? $this->laboratory
            : $this->standard;

        return $builder->build($section, $courses, $options);
    }
}
