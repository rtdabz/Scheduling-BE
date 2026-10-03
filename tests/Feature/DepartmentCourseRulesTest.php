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

    public function test_a_run_override_replaces_saved_rules_for_its_courses_and_is_never_saved(): void
    {
        $department = Departments::create(['department_name' => 'Nursing', 'department_code' => 'NUR']);
        $departmentId = (int) $department->id;
        $saved = $this->course('CLIN 101', $departmentId);
        $other = $this->course('CLIN 102', $departmentId);
        $fresh = $this->course('CLIN 103', $departmentId);

        DepartmentCourseRules::put($departmentId, (int) $saved->id, null, ['forced_day' => 'Monday']);
        DepartmentCourseRules::put($departmentId, (int) $other->id, null, ['forced_day' => 'Friday']);

        $seen = DepartmentCourseRules::withOverride($departmentId, [
            // CLIN 101 and CLIN 103 are in this run; CLIN 102 is not.
            'scope_course_ids' => [(int) $saved->id, (int) $fresh->id],
            'forced_day_rules' => [['course_id' => (int) $fresh->id, 'day' => 'Wednesday']],
            'consecutive_day_rules' => [['course_id' => (int) $saved->id, 'section_id' => null, 'day_count' => 2, 'meeting_days' => ['Thursday', 'Friday']]],
            'field_course_codes' => ['clin  103'],
        ], fn (): array => [
            'forced' => SchedulingPolicy::forcedCourseDayMap($departmentId),
            'consecutive' => SchedulingPolicy::consecutiveDayRuleMap($departmentId, null),
            'field' => DepartmentCourseRules::fieldCourseCodes($departmentId),
            'field_map' => SchedulingPolicy::fieldCourseCodeMap($departmentId),
        ]);

        // The Required Day saved for CLIN 101 is gone for this run, the one
        // set for CLIN 103 is in, and CLIN 102 (outside the run) is untouched.
        $this->assertSame([(int) $other->id => 'Friday', (int) $fresh->id => 'Wednesday'], $seen['forced']);
        $this->assertSame(['Thursday', 'Friday'], $seen['consecutive'][(int) $saved->id]['meeting_days']);
        $this->assertSame(['CLIN 103'], $seen['field']);
        $this->assertSame(['CLIN 103' => true], $seen['field_map']);

        // Afterwards only the saved rules remain, and nothing was written.
        $this->assertSame([(int) $saved->id => 'Monday', (int) $other->id => 'Friday'], SchedulingPolicy::forcedCourseDayMap($departmentId));
        $this->assertSame([], SchedulingPolicy::consecutiveDayRuleMap($departmentId, null));
        $this->assertSame([], DepartmentCourseRules::fieldCourseCodes($departmentId));
        $this->assertSame(2, DB::table(DepartmentCourseRules::TABLE)->count());
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
