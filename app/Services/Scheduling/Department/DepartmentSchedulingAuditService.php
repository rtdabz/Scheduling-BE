<?php

namespace App\Services\Scheduling\Department;

use App\Enums\DepartmentSchedulingProfile;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Services\Scheduling\Support\SchedulingPolicy;

class DepartmentSchedulingAuditService
{
    public function __construct(
        private readonly DepartmentSchedulingProfileResolver $profiles,
    ) {}

    /** @return list<array<string, mixed>> */
    public function audit(?int $departmentId = null): array
    {
        return Departments::query()
            ->when($departmentId !== null, fn ($query) => $query->whereKey($departmentId))
            ->orderBy('department_code')
            ->get()
            ->map(fn (Departments $department): array => $this->auditDepartment($department))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function auditDepartment(Departments $department): array
    {
        $profile = $this->profiles->resolve($department);
        // Across every active curriculum. Reading only the first one made
        // profile_mismatch a false negative for exactly the departments this
        // report exists to catch -- those running two curricula at once.
        $curriculumIds = Curriculum::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->pluck('id');
        $courses = $curriculumIds->isEmpty()
            ? collect()
            : Course::query()
                ->whereHas('curriculum', fn ($scope) => $scope->whereIn('curriculum.id', $curriculumIds))
                ->where('status', 'active')
                ->get(['courses.id', 'course_code', 'lab_hours', 'room_type_required', 'course_category', 'status']);
        $laboratoryCourses = $courses->filter(fn (Course $course): bool => SchedulingPolicy::isLaboratoryCourse($course));

        return [
            'department_id' => (int) $department->id,
            'department_code' => (string) $department->department_code,
            'department_name' => (string) $department->department_name,
            'profile' => $profile->value,
            'active_curriculum' => $curriculumIds->isNotEmpty(),
            'active_course_count' => $courses->count(),
            'laboratory_course_count' => $laboratoryCourses->count(),
            'available_lecture_rooms' => $this->roomCount($department, 'lecture'),
            'available_laboratory_rooms' => $this->roomCount($department, 'laboratory'),
            'profile_mismatch' => $profile === DepartmentSchedulingProfile::STANDARD && $laboratoryCourses->isNotEmpty(),
            'laboratory_settings_enabled' => (bool) $department->lecture_lab_schedule_override_enabled
                || (bool) $department->custom_lab_duration_override_enabled
                || (bool) $department->custom_lab_duration_6_hours_enabled
                || (bool) $department->custom_lab_duration_5_hours_enabled
                || (bool) $department->custom_lab_duration_other_enabled,
            // Reported so a reviewer can see why a course meets twice a week
            // without having to open the department's settings page.
            'balanced_split_settings_enabled' => (bool) $department->gec_split_schedule_override_enabled
                || (bool) $department->major_lecture_split_schedule_override_enabled,
        ];
    }

    private function roomCount(Departments $department, string $roomType): int
    {
        return Rooms::query()
            ->where('status', 'available')
            ->where('room_type', $roomType)
            ->where(fn ($query) => $query->whereNull('department_id')->orWhere('department_id', $department->id))
            ->count();
    }
}
