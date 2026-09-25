<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_constraint_options_are_scoped_to_selected_section_year_and_semester(): void
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        // Schedule capabilities and section scheduling both require the
        // department to own a program.
        $program = \App\Models\Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);

        $yearOneCourse = Course::create(['course_code' => 'IT 101', 'course_name' => 'Programming 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $yearTwoCourse = Course::create(['course_code' => 'IT 201', 'course_name' => 'Programming 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $secondSemesterCourse = Course::create(['course_code' => 'IT 102', 'course_name' => 'Web Systems', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $department->id, 'status' => 'active']);

        $curriculum->courses()->attach($yearOneCourse->id, ['year_level' => 1, 'semester' => 1]);
        $curriculum->courses()->attach($yearTwoCourse->id, ['year_level' => 2, 'semester' => 1]);
        $curriculum->courses()->attach($secondSemesterCourse->id, ['year_level' => 1, 'semester' => 2]);

        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        $response = $this->actingAs($user)->getJson('/api/scheduling-settings?section_id='.$section->id);

        $response->assertOk();
        // Online and field classes are not capped, so there is no limit to report.
        $response->assertJsonMissingPath('online_slot_limit')
            ->assertJsonMissingPath('field_slot_limit');
        $this->assertSame(['IT 101'], collect($response->json('forced_day_courses'))->pluck('code')->all());
        $this->assertSame(['IT 101'], collect($response->json('field_course_options'))->pluck('code')->all());
    }

    public function test_the_three_lab_duration_presets_behave_as_one_choice(): void
    {
        [$user, $department] = $this->laboratoryDepartment();

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_6_hours_enabled' => true,
        ])->assertOk();

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'custom_lab_duration_5_hours_enabled' => true,
        ])->assertOk();

        $department->refresh();
        $this->assertTrue((bool) $department->custom_lab_duration_5_hours_enabled);
        $this->assertFalse((bool) $department->custom_lab_duration_6_hours_enabled, 'picking one preset clears the others');
        $this->assertFalse((bool) $department->custom_lab_duration_other_enabled);
    }

    public function test_custom_lab_duration_cannot_be_enabled_without_the_lecture_lab_split(): void
    {
        [$user, $department] = $this->laboratoryDepartment(splitEnabled: false);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'custom_lab_duration_override_enabled' => true,
        ])->assertStatus(422);

        $this->assertFalse((bool) $department->refresh()->custom_lab_duration_override_enabled);
    }

    public function test_turning_the_split_off_clears_the_lab_duration_override(): void
    {
        [$user, $department] = $this->laboratoryDepartment();

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_6_hours_enabled' => true,
        ])->assertOk();

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'lecture_lab_schedule_override_enabled' => false,
        ])->assertOk();

        $department->refresh();
        $this->assertFalse((bool) $department->custom_lab_duration_override_enabled);
        $this->assertFalse((bool) $department->custom_lab_duration_6_hours_enabled);
    }

    public function test_a_lab_duration_off_the_half_hour_grid_is_rejected(): void
    {
        [$user] = $this->laboratoryDepartment();

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'custom_lab_duration_override_enabled' => true,
            'custom_lab_duration_other_enabled' => true,
            'custom_lab_duration_minutes' => 200,
        ])->assertStatus(422);
    }

    /**
     * A department running two curricula keeps its laboratories wherever they
     * live. Reading only the first active curriculum let it onto the standard
     * profile, and every section on the other curriculum then failed preflight.
     */
    public function test_the_standard_profile_is_refused_for_a_lab_in_a_second_curriculum(): void
    {
        [$user, $department] = $this->laboratoryDepartment();

        $older = Curriculum::create(['name' => 'IT 2024', 'department_id' => $department->id, 'code' => 'IT-2024', 'effective_school_year' => '2024-2025', 'status' => 'active']);
        $lecture = Course::create(['course_code' => 'IT 110', 'course_name' => 'Discrete Math', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $older->courses()->attach($lecture->id, ['year_level' => 1, 'semester' => 1]);

        $newer = Curriculum::create(['name' => 'IT 2026', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $laboratory = Course::create(['course_code' => 'IT 101', 'course_name' => 'Programming 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $newer->courses()->attach($laboratory->id, ['year_level' => 1, 'semester' => 1]);

        // Only the VPAA may change a department's profile.
        $vpaa = $this->grantCapabilities(User::factory()->create(['role' => 'vpaa']));

        $this->actingAs($vpaa)
            ->putJson('/api/departments/'.$department->id, ['scheduling_profile' => 'standard'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'department_profile_mismatch');

        $this->assertSame('laboratory_enabled', $department->refresh()->scheduling_profile);
    }

    /** @return array{0: User, 1: Departments} */
    public function test_major_lecture_split_sessions_is_offered_only_where_a_lecture_only_major_exists(): void
    {
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
        ]);
        \App\Models\Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);
        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'department_id' => $department->id,
            'code' => 'IT-2026',
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        // Lecture + laboratory only: that major belongs to the Lecture +
        // Laboratory override, so this setting has nothing to apply to yet.
        $labMajor = Course::create(['course_code' => 'IT 101', 'course_name' => 'Programming 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $curriculum->courses()->attach($labMajor->id, ['year_level' => 1, 'semester' => 1]);
        $user = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]));

        $this->actingAs($user)->getJson('/api/scheduling-settings')
            ->assertOk()
            ->assertJsonPath('major_lecture_split_available', false)
            ->assertJsonPath('major_lecture_split_schedule_override_enabled', false);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'major_lecture_split_schedule_override_enabled' => true,
        ])->assertStatus(422);

        $lectureMajor = Course::create(['course_code' => 'IT 103', 'course_name' => 'Discrete Structures', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $curriculum->courses()->attach($lectureMajor->id, ['year_level' => 1, 'semester' => 1]);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'major_lecture_split_schedule_override_enabled' => true,
        ])->assertOk()
            ->assertJsonPath('major_lecture_split_available', true)
            ->assertJsonPath('major_lecture_split_schedule_override_enabled', true);

        // Not a laboratory setting: a standard-profile department may use it, and
        // turning it off never needs an eligible course.
        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'major_lecture_split_schedule_override_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('major_lecture_split_schedule_override_enabled', false);
    }

    public function test_sunday_classes_start_off_and_the_secretary_turns_them_on_and_off(): void
    {
        [$user, $department] = $this->laboratoryDepartment();

        $this->actingAs($user)->getJson('/api/scheduling-settings')
            ->assertOk()
            ->assertJsonPath('sunday_classes_enabled', false)
            ->assertJsonPath('can_manage_sunday_classes', true);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', ['sunday_classes_enabled' => true])
            ->assertOk()
            ->assertJsonPath('sunday_classes_enabled', true);
        $this->assertTrue((bool) $department->refresh()->sunday_classes_enabled);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', ['sunday_classes_enabled' => false])
            ->assertOk()
            ->assertJsonPath('sunday_classes_enabled', false);
        $this->assertFalse((bool) $department->refresh()->sunday_classes_enabled);
    }

    public function test_only_the_secretary_can_change_sunday_classes(): void
    {
        [, $department] = $this->laboratoryDepartment();
        $programHead = $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head',
            'department_id' => $department->id,
        ]));

        $this->actingAs($programHead)->getJson('/api/scheduling-settings')
            ->assertOk()
            ->assertJsonPath('can_manage_sunday_classes', false);

        $this->actingAs($programHead)->patchJson('/api/scheduling-settings', ['sunday_classes_enabled' => true])
            ->assertForbidden();
        $this->assertFalse((bool) $department->refresh()->sunday_classes_enabled);

        // Resending the current value is not a change, so it is not refused.
        $this->actingAs($programHead)->patchJson('/api/scheduling-settings', ['sunday_classes_enabled' => false])
            ->assertOk();
    }

    public function test_a_sunday_required_day_needs_sunday_classes(): void
    {
        [$user, $department] = $this->laboratoryDepartment();
        $course = Course::create(['course_code' => 'IT 101', 'course_name' => 'Programming 1', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $rules = ['forced_day_rules' => [['course_id' => $course->id, 'day' => 'Sunday']]];

        $this->actingAs($user)->patchJson('/api/scheduling-settings', $rules)
            ->assertStatus(422);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [...$rules, 'sunday_classes_enabled' => true])
            ->assertOk();
    }

    public function test_consecutive_day_rules_are_saved_per_course_and_per_section(): void
    {
        [$user, $department] = $this->laboratoryDepartment();
        [$course, $section] = $this->clinicalCourseAndSection($department);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', ['consecutive_day_rules' => [
            ['course_id' => $course->id, 'section_id' => null, 'day_count' => 2, 'preferred_start_day' => null],
            ['course_id' => $course->id, 'section_id' => $section->id, 'day_count' => 3, 'preferred_start_day' => 'Thursday'],
        ]])->assertOk()
            ->assertJsonPath('consecutive_day_rules.0.section_id', null)
            ->assertJsonPath('consecutive_day_rules.1.day_count', 3)
            ->assertJsonPath('consecutive_day_rules.1.preferred_start_day', 'Thursday');

        $this->assertSame(
            [$course->id => ['day_count' => 3, 'preferred_start_day' => 'Thursday']],
            \App\Services\Scheduling\Support\SchedulingPolicy::consecutiveDayRuleMap($department->id, $section->id),
        );

        // Sending an empty list clears them.
        $this->actingAs($user)->patchJson('/api/scheduling-settings', ['consecutive_day_rules' => []])
            ->assertOk()
            ->assertJsonPath('consecutive_day_rules', []);
    }

    public function test_a_consecutive_run_must_fit_the_teaching_week(): void
    {
        [$user, $department] = $this->laboratoryDepartment();
        [$course] = $this->clinicalCourseAndSection($department);
        $fromFriday = ['consecutive_day_rules' => [
            ['course_id' => $course->id, 'day_count' => 3, 'preferred_start_day' => 'Friday'],
        ]];

        $this->actingAs($user)->patchJson('/api/scheduling-settings', $fromFriday)
            ->assertStatus(422)
            ->assertJsonPath('message', 'CLIN 101: 3 consecutive days starting Friday run past the end of the Monday-Saturday teaching week. Choose an earlier starting day or fewer days.');
        $this->actingAs($user)->patchJson('/api/scheduling-settings', ['consecutive_day_rules' => [
            ['course_id' => $course->id, 'day_count' => 7],
        ]])->assertStatus(422);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [...$fromFriday, 'sunday_classes_enabled' => true])
            ->assertOk();
    }

    public function test_a_course_cannot_have_both_a_required_day_and_consecutive_days(): void
    {
        [$user, $department] = $this->laboratoryDepartment();
        [$course] = $this->clinicalCourseAndSection($department);

        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'forced_day_rules' => [['course_id' => $course->id, 'day' => 'Thursday']],
        ])->assertOk();

        $this->actingAs($user)->patchJson('/api/scheduling-settings', ['consecutive_day_rules' => [
            ['course_id' => $course->id, 'day_count' => 3],
        ]])->assertStatus(422)
            ->assertJsonPath('message', 'CLIN 101 has a Required Day of Thursday, so it cannot also meet on consecutive days. Clear its Required Day, or tick its meeting days instead.');

        // Clearing the Required Day in the same save lets it through.
        $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'forced_day_rules' => [],
            'consecutive_day_rules' => [['course_id' => $course->id, 'day_count' => 3, 'preferred_start_day' => 'Thursday']],
        ])->assertOk();
    }

    public function test_consecutive_days_are_only_set_for_the_departments_own_sections(): void
    {
        [$user, $department] = $this->laboratoryDepartment();
        [$course] = $this->clinicalCourseAndSection($department);
        $otherDepartment = Departments::create(['department_name' => 'Nursing', 'department_code' => 'NUR']);
        [, $foreignSection] = $this->clinicalCourseAndSection($otherDepartment, 'CLIN 201');

        $this->actingAs($user)->patchJson('/api/scheduling-settings', ['consecutive_day_rules' => [
            ['course_id' => $course->id, 'section_id' => $foreignSection->id, 'day_count' => 3],
        ]])->assertStatus(422);
    }

    /** @return array{0: Course, 1: Sections} */
    private function clinicalCourseAndSection(Departments $department, string $code = 'CLIN 101'): array
    {
        $semester = Semester::query()->first()
            ?? Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $program = \App\Models\Program::query()->where('department_id', $department->id)->first()
            ?? \App\Models\Program::create(['department_id' => $department->id, 'code' => $department->department_code.'P', 'name' => $department->department_name]);
        $course = Course::create(['course_code' => $code, 'course_name' => 'Clinical Duty', 'lecture_hours' => 0, 'lab_hours' => 4, 'units' => 4, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        // Rules are set for the active curriculum's courses, like Required Days.
        $curriculum = Curriculum::create(['name' => $code.' Curriculum', 'department_id' => $department->id, 'code' => $code.'-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
        $section = Sections::create([
            'section_name' => $department->department_code.' 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);

        return [$course, $section];
    }

    private function laboratoryDepartment(bool $splitEnabled = true): array
    {
        $department = Departments::create([
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'scheduling_profile' => 'laboratory_enabled',
            'lecture_lab_schedule_override_enabled' => $splitEnabled,
        ]);
        \App\Models\Program::create([
            'department_id' => $department->id,
            'code' => 'BSIT',
            'name' => 'Information Technology',
        ]);

        $user = $this->grantCapabilities(User::factory()->create([
            'role' => 'secretary',
            'department_id' => $department->id,
        ]));

        return [$user, $department];
    }
}
