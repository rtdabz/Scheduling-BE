<?php

use App\Models\Course;
use App\Models\Semester;
use App\Services\CourseTeachingAssignments;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $semesterId = Semester::query()->where('is_active', true)->value('id');
        if ($semesterId === null) {
            return;
        }

        // Repair existing exhausted delegations using the same checks as new returns.
        $assignments = app(CourseTeachingAssignments::class);
        Course::query()->whereNotNull('teaching_department_id')->get(['id', 'teaching_department_id'])
            ->groupBy('teaching_department_id')
            ->each(fn ($courses, $departmentId) => $assignments->clearFullyReturned(
                $courses->modelKeys(), (int) $semesterId, (int) $departmentId,
            ));
    }

    public function down(): void
    {
        // A rollback must not revive teaching assignments already returned by their receiver.
    }
};
