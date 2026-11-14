<?php

namespace App\Services\Scheduling\Schedule;

use App\Models\Course;
use App\Models\Schedule;

/**
 * A course delegated to a teaching department is scheduled once, by the department that owns it;
 * the teaching department assigns the instructor on that row instead of creating another schedule.
 */
final class DelegatedCourseSchedules
{
    public function refusal(int $courseId, int $targetDepartmentId, int $semesterId): ?string
    {
        $course = Course::query()->find($courseId);
        $teachingDepartmentId = (int) ($course?->teaching_department_id ?? 0);
        if ($course === null || $teachingDepartmentId === 0 || $teachingDepartmentId !== $targetDepartmentId) {
            return null;
        }

        $sourceSchedule = Schedule::query()
            ->where('semester_id', $semesterId)
            ->where('course_id', $courseId)
            ->where('department_id', '!=', $targetDepartmentId)
            ->whereNotIn('status', ['rejected', 'revision'])
            ->first();

        if ($sourceSchedule === null) {
            return null;
        }

        return "{$course->course_code} already has a schedule owned by the source department. Assign the instructor to the existing schedule; do not create another schedule.";
    }
}
