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
        // Neither shared resource is capped unless a department sets a limit,
        // so an unconfigured department reports null rather than a ceiling the
        // scheduler does not apply.
        $response->assertJsonPath('online_slot_limit', null)
            ->assertJsonPath('field_slot_limit', null);
        $this->assertSame(['IT 101'], collect($response->json('forced_day_courses'))->pluck('code')->all());
        $this->assertSame(['IT 101'], collect($response->json('field_course_options'))->pluck('code')->all());

        $update = $this->actingAs($user)->patchJson('/api/scheduling-settings', [
            'online_slot_limit' => 8,
            'field_slot_limit' => 5,
        ]);

        $update->assertOk()
            ->assertJsonPath('online_slot_limit', 8)
            ->assertJsonPath('field_slot_limit', 5);
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
