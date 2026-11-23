<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Maintains the existing course API/SQL fields as a projection of the active semester. */
final class CourseTeachingAssignments
{
    public const FIELDS = ['teaching_department_id', 'teaching_program_id', 'teaching_source_program_id'];

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
