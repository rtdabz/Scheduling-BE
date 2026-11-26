<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseTeachingAssignment;
use App\Models\Schedule;
use App\Models\Semester;
use Illuminate\Support\Facades\DB;

/** Maintains the existing course API/SQL fields as a projection of the active semester. */
final class CourseTeachingAssignments
{
    public const FIELDS = ['teaching_department_id', 'teaching_program_id', 'teaching_source_program_id'];

    /**
     * Clear an exhausted course delegation after its last class is returned.
     * Detached sections keep their own targets, instructors and return history.
     *
     * @param  list<int>  $courseIds
     * @return list<int>
     */
    public function clearFullyReturned(array $courseIds, int $semesterId, int $returningDepartmentId): array
    {
        if ($courseIds === []) {
            return [];
        }

        return DB::transaction(function () use ($courseIds, $semesterId, $returningDepartmentId): array {
            $active = Semester::query()->orderBy('id')->lockForUpdate()->get()->firstWhere('is_active', true);
            if ((int) $active?->id !== $semesterId) {
                return [];
            }
            $courses = Course::query()->whereIn('id', $courseIds)
                ->where('teaching_department_id', $returningDepartmentId)->orderBy('id')->lockForUpdate()->get();
            $assignments = CourseTeachingAssignment::query()->where('semester_id', $semesterId)
                ->whereIn('course_id', $courses->modelKeys())->get()->keyBy('course_id');
            $schedules = Schedule::query()->where('semester_id', $semesterId)
                ->whereIn('course_id', $courses->modelKeys())->lockForUpdate()
                ->get(['course_id', 'delegation_returned_from_department_id', 'delegation_returned_at'])->groupBy('course_id');

            $cleared = [];
            foreach ($courses as $course) {
                $rows = $schedules->get($course->id, collect());
                // Check every offering, including other programs and departments using the course.
                if ($rows->isEmpty() || $rows->contains(fn (Schedule $row): bool => $row->delegation_returned_from_department_id === null)) {
                    continue;
                }
                $returnedAt = $rows->where('delegation_returned_from_department_id', $returningDepartmentId)
                    ->pluck('delegation_returned_at')->filter()->sort()->last();
                // An intentional assignment saved after the return must remain assigned.
                if ($returnedAt === null || $assignments->get($course->id)?->updated_at?->gt($returnedAt)) {
                    continue;
                }
                $course->update(array_fill_keys(self::FIELDS, null));
                $cleared[] = (int) $course->id;
            }

            return $cleared;
        }, 3);
    }

    /** Restore this semester's saved delegation, or start unassigned when none exists. */
    public function activate(int $semesterId): void
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = DB::table('course_teaching_assignments')->select($field)
                ->whereColumn('course_teaching_assignments.course_id', 'courses.id')
                ->where('semester_id', $semesterId);
        }
        // Include archived courses so restoring a course cannot revive another semester's delegation.
        DB::table('courses')->update($values);
        // Section-level delegations already belong to semester-specific schedule rows.
        // Leave them intact so returning to this semester also restores those decisions.
    }
}
