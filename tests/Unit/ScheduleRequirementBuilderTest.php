<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Sections;
use App\Services\Scheduling\LaboratoryScheduleRequirementBuilder;
use App\Services\Scheduling\StandardScheduleRequirementBuilder;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ScheduleRequirementBuilderTest extends TestCase
{
    public function test_standard_builder_never_allows_a_laboratory_room_for_a_lecture_course(): void
    {
        $course = new Course([
            'id' => 10,
            'units' => 3,
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
        ]);
        $course->id = 10;

        $requirements = app(StandardScheduleRequirementBuilder::class)->build(
            new Sections,
            new Collection([$course]),
        );

        $this->assertSame(['lecture'], $requirements[10][0]['eligible_room_types']);
        $this->assertFalse($requirements[10][0]['allow_lecture_laboratory_fallback']);
    }

    public function test_standard_builder_keeps_nstp_on_field_delivery_even_when_global_mode_is_on_site(): void
    {
        $course = new Course([
            'id' => 11,
            'course_code' => 'NSTP 1',
            'units' => 3,
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
        ]);
        $course->id = 11;

        $requirements = app(StandardScheduleRequirementBuilder::class)->build(
            new Sections,
            new Collection([$course]),
            ['mode' => 'on-site'],
        );

        $this->assertSame('field', $requirements[11][0]['component_type']);
        $this->assertSame(['field'], $requirements[11][0]['allowed_delivery_modes']);
    }

    public function test_laboratory_builder_emits_separate_lecture_and_laboratory_components(): void
    {
        $department = new Departments(['lecture_lab_schedule_override_enabled' => true]);
        $section = new Sections;
        $section->setRelation('department', $department);
        $course = new Course([
            'id' => 20,
            'units' => 3,
            'lecture_hours' => 2,
            'lab_hours' => 1,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
        ]);
        $course->id = 20;

        $requirements = app(LaboratoryScheduleRequirementBuilder::class)->build(
            $section,
            new Collection([$course]),
            ['selected_split_session_course_ids' => [20]],
        );

        $this->assertSame(['lecture', 'laboratory'], array_column($requirements[20], 'component_type'));
        $this->assertSame(['online'], $requirements[20][0]['eligible_room_types']);
        $this->assertSame(['laboratory'], $requirements[20][1]['eligible_room_types']);
        $this->assertTrue($requirements[20][0]['is_split_component']);
        $this->assertTrue($requirements[20][1]['is_split_component']);
    }
}
