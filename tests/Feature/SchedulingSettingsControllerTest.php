<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_constraint_options_are_scoped_to_selected_section_year_and_semester(): void
    {
        $term = Terms::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);
        $curriculum = Curriculum::create(['name' => 'IT Curriculum', 'department_id' => $department->id, 'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active']);

        $yearOneCourse = Course::create(['course_code' => 'IT 101', 'course_name' => 'Programming 1', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $yearTwoCourse = Course::create(['course_code' => 'IT 201', 'course_name' => 'Programming 2', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'laboratory', 'year_level' => '2', 'semester' => '1st', 'department_id' => $department->id, 'status' => 'active']);
        $secondSemesterCourse = Course::create(['course_code' => 'IT 102', 'course_name' => 'Web Systems', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $department->id, 'status' => 'active']);

        $curriculum->courses()->attach($yearOneCourse->id, ['year_level' => 1, 'semester' => 1]);
        $curriculum->courses()->attach($yearTwoCourse->id, ['year_level' => 2, 'semester' => 1]);
        $curriculum->courses()->attach($secondSemesterCourse->id, ['year_level' => 1, 'semester' => 2]);

        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]);

        $response = $this->actingAs($user)->getJson('/api/scheduling-settings?section_id='.$section->id);

        $response->assertOk();
        $this->assertSame(['IT 101'], collect($response->json('forced_day_courses'))->pluck('code')->all());
        $this->assertSame(['IT 101'], collect($response->json('field_course_options'))->pluck('code')->all());
    }
}
