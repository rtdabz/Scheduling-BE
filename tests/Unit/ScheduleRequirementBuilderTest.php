<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Sections;
use App\Services\Scheduling\Generation\LaboratoryScheduleRequirementBuilder;
use App\Services\Scheduling\Generation\StandardScheduleRequirementBuilder;
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

        $this->assertSame(['lecture', 'online'], $requirements[10][0]['eligible_room_types']);
        $this->assertNotContains('laboratory', $requirements[10][0]['eligible_room_types']);
        $this->assertFalse($requirements[10][0]['allow_lecture_laboratory_fallback']);
    }

    public function test_standard_builder_schedules_nstp_as_a_normal_minor_unless_the_department_made_it_field(): void
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

        // NSTP/ROTC/CWTS are no longer field by name: the department makes a
        // course field by giving it a field room.
        $this->assertSame('lecture', $requirements[11][0]['component_type']);
        $this->assertNotContains('field', $requirements[11][0]['allowed_delivery_modes']);
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

    public function test_laboratory_builder_keeps_the_unit_derived_lab_length_without_the_override(): void
    {
        $requirements = $this->splitRequirements(new Departments([
            'lecture_lab_schedule_override_enabled' => true,
        ]));

        // 2 lecture units -> 2 hours; 1 laboratory unit -> 3 hours.
        $this->assertSame(4, $requirements[20][0]['duration_slots']);
        $this->assertSame(6, $requirements[20][1]['duration_slots']);
    }

    public function test_custom_lab_duration_resizes_only_the_laboratory_half(): void
    {
        $requirements = $this->splitRequirements(new Departments([
            'lecture_lab_schedule_override_enabled' => true,
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_5_hours_enabled' => true,
        ]));

        $this->assertSame(4, $requirements[20][0]['duration_slots'], 'the lecture half still follows lecture units');
        $this->assertSame(10, $requirements[20][1]['duration_slots'], 'five hours is ten slots');
    }

    public function test_custom_lab_duration_accepts_an_entered_number_of_hours(): void
    {
        $requirements = $this->splitRequirements(new Departments([
            'lecture_lab_schedule_override_enabled' => true,
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_other_enabled' => true,
            'custom_lab_duration_minutes' => 210,
        ]));

        $this->assertSame(7, $requirements[20][1]['duration_slots'], 'three and a half hours is seven slots');
    }

    public function test_custom_lab_duration_replaces_rather_than_scales_the_unit_derived_length(): void
    {
        $requirements = $this->splitRequirements(
            new Departments([
                'lecture_lab_schedule_override_enabled' => true,
                'custom_lab_duration_override_enabled' => true,
                'custom_lab_duration_6_hours_enabled' => true,
            ]),
            labUnits: 2,
        );

        // Two laboratory units would derive twelve hours. The override is the
        // whole component's length, not a per-unit rate.
        $this->assertSame(12, $requirements[20][1]['duration_slots']);
    }

    public function test_a_custom_lab_duration_off_the_slot_grid_is_ignored(): void
    {
        $requirements = $this->splitRequirements(new Departments([
            'lecture_lab_schedule_override_enabled' => true,
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_other_enabled' => true,
            'custom_lab_duration_minutes' => 200,
        ]));

        $this->assertSame(6, $requirements[20][1]['duration_slots'], 'falls back to the unit-derived length');
    }

    public function test_custom_lab_duration_does_nothing_while_the_override_is_off(): void
    {
        $requirements = $this->splitRequirements(new Departments([
            'lecture_lab_schedule_override_enabled' => true,
            'custom_lab_duration_override_enabled' => false,
            'custom_lab_duration_6_hours_enabled' => true,
        ]));

        $this->assertSame(6, $requirements[20][1]['duration_slots']);
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function splitRequirements(Departments $department, int $labUnits = 1): array
    {
        $section = new Sections;
        $section->setRelation('department', $department);
        $course = new Course([
            'id' => 20,
            'units' => 3,
            'lecture_hours' => 2,
            'lab_hours' => $labUnits,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
        ]);
        $course->id = 20;

        return app(LaboratoryScheduleRequirementBuilder::class)->build(
            $section,
            new Collection([$course]),
            ['selected_split_session_course_ids' => [20]],
        );
    }
}
