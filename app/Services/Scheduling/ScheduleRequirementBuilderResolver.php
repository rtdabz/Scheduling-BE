<?php

namespace App\Services\Scheduling;

use App\Enums\DepartmentSchedulingProfile;
use App\Models\Course;
use App\Models\Departments;
use App\Models\Sections;

class ScheduleRequirementBuilderResolver
{
    /** @var array<int, Course> Courses are immutable during requirement building. */
    private array $courseCache = [];

    public function __construct(
        private readonly DepartmentSchedulingProfileResolver $profiles,
        private readonly StandardScheduleRequirementBuilder $standard,
        private readonly LaboratoryScheduleRequirementBuilder $laboratory,
    ) {}

    /** @return array<int, list<array<string, mixed>>> */
    public function build(Sections $section, array $courseIds, array $options = []): array
    {
        $department = $section->department ?: Departments::query()->findOrFail((int) $section->department_id);
        $normalizedIds = array_values(array_unique(array_map('intval', $courseIds)));
        $missingIds = array_values(array_filter(
            $normalizedIds,
            fn (int $courseId): bool => ! isset($this->courseCache[$courseId]),
        ));
        if ($missingIds !== []) {
            $loaded = Course::query()
                ->with('categories')
                ->whereIn('id', $missingIds)
                ->get();
            foreach ($loaded as $course) {
                $this->courseCache[(int) $course->id] = $course;
            }
        }
        $courses = collect($normalizedIds)
            ->map(fn (int $courseId): ?Course => $this->courseCache[$courseId] ?? null)
            ->filter();
        $builder = $this->profiles->resolve($department) === DepartmentSchedulingProfile::LABORATORY_ENABLED
            ? $this->laboratory
            : $this->standard;

        return $builder->build($section, $courses, $options);
    }
}
