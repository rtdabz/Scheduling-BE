<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Services\Scheduling\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\SchedulingPolicy;
use App\Services\Scheduling\YearLevelFeasibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FieldCourseRoomTypeInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_course_remains_field_when_a_legacy_component_says_lecture(): void
    {
        $course = new Course([
            'course_code' => 'PATHFIT 1',
            'course_name' => 'Physical Activities',
            'course_category' => 'minor',
            'room_type_required' => 'field',
        ]);

        $this->assertSame('field', SchedulingPolicy::effectiveRoomType($course, null, 'lecture'));
        $this->assertSame('field', SchedulingPolicy::effectiveRoomType($course, null, 'laboratory'));
        $this->assertSame('field', SchedulingConstraintPredicates::effectiveRoomType([
            'course_code' => 'PATHFIT 1',
            'course_category' => 'minor',
            'room_type_required' => 'field',
        ], [], 'lecture'));
    }

    public function test_department_scoped_field_configuration_classifies_shared_course_as_field_for_generation(): void
    {
        $department = \App\Models\Departments::create([
            'department_name' => 'College of Computer Studies',
            'department_code' => 'CCS',
            'status' => 'active',
        ]);
        $term = \App\Models\Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $section = \App\Models\Sections::create([
            'section_name' => 'BSIT 1A',
            'department_id' => $department->id,
            'year_level' => '1',
            'semester' => '1st',
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'PATH FIT 1',
            'course_name' => 'Physical Activities',
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'year_level' => '1',
            'semester' => '1st',
            'status' => 'active',
        ]);
        DB::table('field_course_settings')->insert([
            'department_id' => $department->id,
            'enabled' => true,
            'course_code' => 'PATH FIT 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SchedulingPolicy::clearFieldCourseCache();

        $requirements = app(ScheduleRequirementBuilderResolver::class)->build($section, [$course->id]);

        $this->assertSame('field', $requirements[$course->id][0]['component_type']);
        $this->assertSame(['field'], $requirements[$course->id][0]['eligible_room_types']);
        $this->assertSame(['field'], $requirements[$course->id][0]['allowed_delivery_modes']);
    }

    public function test_field_course_preflight_does_not_treat_field_demand_as_regular_room_demand(): void
    {
        $department = \App\Models\Departments::create([
            'department_name' => 'College of Computer Studies',
            'department_code' => 'CCS',
            'status' => 'active',
        ]);
        $term = \App\Models\Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $section = \App\Models\Sections::create([
            'section_name' => 'BSIT 1A',
            'department_id' => $department->id,
            'year_level' => '1',
            'semester' => '1st',
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $course = Course::create([
            'course_code' => 'PATH FIT 1',
            'course_name' => 'Physical Activities',
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'units' => 2,
            'year_level' => '1',
            'semester' => '1st',
            'status' => 'active',
        ]);
        DB::table('field_course_settings')->insert([
            'department_id' => $department->id,
            'enabled' => true,
            'course_code' => 'PATH FIT 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SchedulingPolicy::clearFieldCourseCache();

        $result = app(YearLevelFeasibilityService::class)->check(
            [$section],
            [
                $section->id => [
                    'course_ids' => [$course->id],
                    'delivery_modes_by_course_id' => [],
                ],
            ],
        );

        $this->assertSame([], $result);
    }
}
