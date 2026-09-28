<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Services\Scheduling\Support\DepartmentCourseRules;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Required Day, Field Course and Consecutive Days share one row per
 * department, course and section. Setting or clearing one rule must leave the
 * others alone.
 */
class DepartmentCourseRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_share_a_row_and_clear_independently(): void
    {
        $department = Departments::create(['department_name' => 'Nursing', 'department_code' => 'NUR']);
        $course = $this->course('CLIN 101', $department->id);
        $departmentId = (int) $department->id;

        DepartmentCourseRules::put($departmentId, (int) $course->id, null, ['forced_day' => 'Monday']);
        DepartmentCourseRules::put($departmentId, (int) $course->id, null, ['is_field' => true]);
        DepartmentCourseRules::put($departmentId, (int) $course->id, null, ['consecutive_day_count' => 3, 'preferred_start_day' => 'Monday']);

        $this->assertSame(1, DB::table(DepartmentCourseRules::TABLE)->count());

        DepartmentCourseRules::clear($departmentId, DepartmentCourseRules::RULE_REQUIRED_DAY, [(int) $course->id]);
        SchedulingPolicy::clearFieldCourseCache();

        $this->assertSame([], SchedulingPolicy::forcedCourseDayMap($departmentId));
        $this->assertSame(['CLIN 101'], DepartmentCourseRules::fieldCourseCodes($departmentId));
        $this->assertSame(3, SchedulingPolicy::consecutiveDayRuleMap($departmentId, null)[(int) $course->id]['day_count']);

        DepartmentCourseRules::clear($departmentId, DepartmentCourseRules::RULE_FIELD, [(int) $course->id]);
        DepartmentCourseRules::clear($departmentId, DepartmentCourseRules::RULE_CONSECUTIVE, [(int) $course->id]);

        $this->assertSame(0, DB::table(DepartmentCourseRules::TABLE)->count(), 'A row with no rule left is removed.');
    }

    private function course(string $code, ?int $departmentId): Course
    {
        return Course::create([
            'course_code' => $code,
            'course_name' => "Course {$code}",
            'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => $departmentId, 'status' => 'active',
        ]);
    }
}
