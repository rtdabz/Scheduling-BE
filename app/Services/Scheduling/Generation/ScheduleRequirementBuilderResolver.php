<?php

namespace App\Services\Scheduling\Generation;

use App\Models\Course;
use App\Models\Sections;

class ScheduleRequirementBuilderResolver
{
    /** @var array<int, Course> Courses are immutable during requirement building. */
    private array $courseCache = [];

    public function __construct(
        private readonly CourseScheduleRequirementBuilder $builder,
    ) {}

    /** @return array<int, list<array<string, mixed>>> */
    public function build(Sections $section, array $courseIds, array $options = []): array
    {
        $normalizedIds = array_values(array_unique(array_map('intval', $courseIds)));
        $missingIds = array_values(array_filter(
            $normalizedIds,
            fn (int $courseId): bool => ! isset($this->courseCache[$courseId]),
        ));
        if ($missingIds !== []) {
            $loaded = Course::query()
                ->whereIn('id', $missingIds)
                ->get();
            foreach ($loaded as $course) {
                $this->courseCache[(int) $course->id] = $course;
            }
        }
        $courses = collect($normalizedIds)
            ->map(fn (int $courseId): ?Course => $this->courseCache[$courseId] ?? null)
            ->filter();
        return $this->builder->build($section, $courses, $options);
    }
}
