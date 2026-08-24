<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the per-course teaching-department override and its rows.
 *
 * The VPAA "Dept. Course Assignments" page wrote a row per course naming which
 * college was allowed to teach it. The whole feature is retired: page, route,
 * controller, model and this table.
 *
 * The rule it fed survives in its derived form — a GEC service subject is taught
 * by the college that offers it — which `SchedulingPolicy::assignedTeachingDepartmentId`
 * now reads straight off `courses.department_id`. Any other minor is open to every
 * department, and a major is still held to its own department and program.
 *
 * `dropIfExists` because the create migration is gone, so `migrate:fresh` never
 * makes the table in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('course_teaching_assignments');
    }

    public function down(): void
    {
        // Not recreated: the feature is retired, and nothing reads the table.
        // Restoring an empty table would only re-establish a schema no code uses.
    }
};
