<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Sections;
use App\Services\Scheduling\Generation\CourseScheduleRequirementBuilder;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ScheduleRequirementBuilderTest extends TestCase
{
    public function test_builder_never_allows_a_laboratory_room_for_a_minor_lecture_course(): void
    {
        $course = new Course([
            'id' => 10,
            'units' => 3,
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
        ]);
        $course->id = 10;

        $requirements = app(CourseScheduleRequirementBuilder::class)->build(
            new Sections,
            new Collection([$course]),
        );

        $this->assertSame(['lecture', 'online'], $requirements[10][0]['eligible_room_types']);
        $this->assertNotContains('laboratory', $requirements[10][0]['eligible_room_types']);
        $this->assertFalse($requirements[10][0]['allow_lecture_laboratory_fallback']);
    }

    public function test_builder_schedules_nstp_as_a_normal_minor_unless_the_department_made_it_field(): void
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

        $requirements = app(CourseScheduleRequirementBuilder::class)->build(
            new Sections,
            new Collection([$course]),
            ['mode' => 'on-site'],
        );

        // NSTP/ROTC/CWTS are no longer field by name: the department makes a
        // course field by giving it a field room.
        $this->assertSame('lecture', $requirements[11][0]['component_type']);
        $this->assertNotContains('field', $requirements[11][0]['allowed_delivery_modes']);
    }

    public function test_builder_emits_separate_lecture_and_laboratory_components(): void
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

        $requirements = app(CourseScheduleRequirementBuilder::class)->build(
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

    public function test_builder_sizes_each_component_from_the_course_hours(): void
    {
        $requirements = $this->splitRequirements(new Departments([
            'lecture_lab_schedule_override_enabled' => true,
        ]));

        // 2 lecture hours and 1 laboratory hour, read as recorded.
        $this->assertSame(4, $requirements[20][0]['duration_slots']);
        $this->assertSame(2, $requirements[20][1]['duration_slots']);
        $this->assertSame(4, $this->splitRequirements(new Departments, 2)[20][1]['duration_slots']);
    }

    public function test_builder_gives_a_single_meeting_the_course_total_hours(): void
    {
        $section = new Sections;
        $section->setRelation('department', new Departments);
        $course = new Course(['units' => 3, 'lecture_hours' => 2, 'lab_hours' => 1, 'course_category' => 'major', 'room_type_required' => 'laboratory']);
        $course->id = 21;

        $requirements = app(CourseScheduleRequirementBuilder::class)->build($section, new Collection([$course]), []);

        $this->assertSame(6, $requirements[21][0]['duration_slots']);
        $this->assertArrayNotHasKey('custom_duration', $requirements[21][0]);
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

        return app(CourseScheduleRequirementBuilder::class)->build(
            $section,
            new Collection([$course]),
            ['selected_split_session_course_ids' => [20]],
        );
    }
}
