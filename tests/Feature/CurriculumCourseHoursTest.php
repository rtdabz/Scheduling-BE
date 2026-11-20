<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Generation\CourseScheduleRequirementBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Edit Hours per Unit: a course's hours per lecture and laboratory unit are set per curriculum
 * placement; academic units never change, and scheduling reads the recalculated minutes.
 */
class CurriculumCourseHoursTest extends TestCase
{
    use RefreshDatabase;

    private Departments $department;

    private User $secretary;

    private Course $course;

    private Curriculum $curriculum;

    private Semester $semester;

    private Sections $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $this->department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        // Curriculum authoring (curriculum.manage) needs the department to own a program.
        \App\Models\Program::create(['department_id' => $this->department->id, 'code' => 'BSIT']);
        $this->secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]);
        // IT 105: 2 lecture + 1 laboratory unit, entered by hand (legacy: 1 unit = 1 hour).
        $this->course = Course::create([
            'course_code' => 'IT 105', 'course_name' => 'Computer Programming 2',
            'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major',
            'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $this->department->id, 'status' => 'active',
        ]);
        $this->curriculum = Curriculum::create(['name' => 'BSIT 2024', 'department_id' => $this->department->id, 'code' => 'BSIT-2024', 'effective_school_year' => '2024-2025', 'status' => 'active']);
        $this->curriculum->courses()->attach($this->course->id, ['year_level' => 1, 'semester' => 1]);
        $this->section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st', 'curriculum_id' => $this->curriculum->id,
            'department_id' => $this->department->id, 'semester_id' => $this->semester->id, 'status' => 'active',
        ]);
    }

    public function test_the_curriculum_detail_reports_each_courses_hours_and_the_department_defaults(): void
    {
        $response = $this->actingAs($this->secretary)->getJson('/api/curriculum/'.$this->curriculum->id.'/full')->assertOk();

        $response->assertJsonPath('curriculum.hours_defaults.lecture_minutes_per_unit', 60)
            ->assertJsonPath('curriculum.hours_defaults.laboratory_minutes_per_unit', 180)
            ->assertJsonPath('semesters.0.courses.0.hours.source', 'legacy')
            ->assertJsonPath('semesters.0.courses.0.hours.lecture_minutes', 120)
            ->assertJsonPath('semesters.0.courses.0.hours.laboratory_minutes', 60)
            ->assertJsonPath('semesters.0.courses.0.hours.total_minutes', 180);
    }

    public function test_custom_hours_per_unit_recalculate_the_required_hours_without_changing_units(): void
    {
        $this->updateHours(['lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180])
            ->assertOk()
            ->assertJsonPath('hours.source', 'custom')
            ->assertJsonPath('hours.lecture_minutes', 120)
            ->assertJsonPath('hours.laboratory_minutes', 180)
            ->assertJsonPath('hours.total_minutes', 300);

        $this->course->refresh();
        $this->assertSame([2, 1, 3], [(int) $this->course->lecture_hours, (int) $this->course->lab_hours, (int) $this->course->units]);

        // The generator sizes the course from the new hours: 2 h lecture + 3 h laboratory.
        $requirements = app(CourseScheduleRequirementBuilder::class)->build(
            $this->section->fresh(), collect([$this->course->fresh()]), ['selected_split_session_course_ids' => [(int) $this->course->id]],
        );
        $this->assertSame([4, 6], array_column($requirements[(int) $this->course->id], 'duration_slots'));
    }

    public function test_department_default_can_be_applied(): void
    {
        $this->department->forceFill(['lecture_minutes_per_unit' => 90, 'laboratory_minutes_per_unit' => 120])->save();

        $this->updateHours(['use_department_default' => true])
            ->assertOk()
            ->assertJsonPath('hours.source', 'department')
            ->assertJsonPath('hours.lecture_minutes', 180)
            ->assertJsonPath('hours.laboratory_minutes', 120);
    }

    public function test_zero_hours_per_unit_leaves_that_component_unscheduled(): void
    {
        $this->updateHours(['lecture_minutes_per_unit' => 90, 'laboratory_minutes_per_unit' => 0])
            ->assertOk()
            ->assertJsonPath('hours.laboratory_minutes_per_unit', 0)
            ->assertJsonPath('hours.laboratory_minutes', 0)
            ->assertJsonPath('hours.total_minutes', 180);

        // The course keeps its laboratory unit but meets only as its 3-hour lecture, never as a 0-length laboratory.
        $requirements = app(CourseScheduleRequirementBuilder::class)->build(
            $this->section->fresh(), collect([$this->course->fresh()]), ['selected_split_session_course_ids' => [(int) $this->course->id]],
        );
        $this->assertCount(1, $requirements[(int) $this->course->id]);
        $this->assertSame([6], array_column($requirements[(int) $this->course->id], 'duration_slots'));
    }

    public function test_rates_must_fit_the_scheduling_grid_and_day(): void
    {
        $this->updateHours(['lecture_minutes_per_unit' => 45, 'laboratory_minutes_per_unit' => 180])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lecture_minutes_per_unit');

        $this->course->update(['lecture_hours' => 3]);
        $this->updateHours(['lecture_minutes_per_unit' => 600, 'laboratory_minutes_per_unit' => 180])
            ->assertUnprocessable();
    }

    public function test_approved_classes_block_a_change_in_hours(): void
    {
        $this->schedule('approved');

        $this->updateHours(['lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180])
            ->assertUnprocessable()
            ->assertJsonPath('blocking_sections', ['IT 1A']);

        $this->assertNull(DB::table('curriculum_course')->where('course_id', $this->course->id)->value('laboratory_minutes'));
    }

    public function test_draft_classes_need_confirmation(): void
    {
        $this->schedule('draft');

        $this->updateHours(['lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180])
            ->assertStatus(409)
            ->assertJsonPath('requires_confirmation', true);

        $this->updateHours(['lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180, 'confirm_draft_changes' => true])
            ->assertOk()
            ->assertJsonPath('hours.laboratory_minutes', 180);
    }

    public function test_a_change_that_keeps_the_same_hours_is_not_blocked(): void
    {
        $this->schedule('approved');

        // Legacy hours are 60 / 60 per unit: saving them explicitly changes no class time.
        $this->updateHours(['lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 60])
            ->assertOk()
            ->assertJsonPath('hours.source', 'custom');
    }

    public function test_changing_a_courses_units_recalculates_its_placement_minutes(): void
    {
        $this->updateHours(['lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180])->assertOk();

        $this->grantCapabilities($this->secretary, ['schedule.view', 'schedule.create']);
        $this->actingAs($this->secretary)->putJson('/api/courses/'.$this->course->id, [
            'course_code' => 'IT 105', 'course_name' => 'Computer Programming 2', 'course_category' => 'major',
            'lecture_hours' => 2, 'lab_hours' => 2, 'units' => 4, 'room_type_required' => 'laboratory',
        ])->assertOk();

        $row = DB::table('curriculum_course')->where('course_id', $this->course->id)->first();
        $this->assertSame([2, 120, 360], [(int) $row->lab_units, (int) $row->lecture_minutes, (int) $row->laboratory_minutes]);
    }

    public function test_only_curriculum_authors_may_edit_hours(): void
    {
        $other = Departments::create(['department_name' => 'Education', 'department_code' => 'ED']);
        $outsider = User::factory()->create(['role' => 'secretary', 'department_id' => $other->id]);

        $this->actingAs($outsider)->putJson($this->hoursUrl(), ['lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180])
            ->assertForbidden();
    }

    private function hoursUrl(): string
    {
        return '/api/curriculum/'.$this->curriculum->id.'/courses/'.$this->course->id.'/hours';
    }

    private function updateHours(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->secretary)->putJson($this->hoursUrl(), $payload);
    }

    private function schedule(string $status): void
    {
        Schedule::create([
            'semester_id' => $this->semester->id, 'section_id' => $this->section->id, 'curriculum_id' => $this->curriculum->id,
            'course_id' => $this->course->id, 'department_id' => $this->department->id, 'day' => 'Monday',
            'start_time' => '08:00', 'end_time' => '11:00', 'mode' => 'on-site', 'status' => $status,
        ]);
    }
}
