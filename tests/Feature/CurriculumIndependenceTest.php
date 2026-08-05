<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Curriculum;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumIndependenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_departments_can_create_independent_courses_with_same_code()
    {
        $user = User::create([
            'name' => 'VPAA User',
            'username' => 'vpaa_admin',
            'email' => 'vpaa@example.com',
            'password' => bcrypt('password'),
            'role' => 'vpaa',
        ]);
        $this->actingAs($user);

        $dept1 = Departments::create(['department_name' => 'Dept 1', 'department_code' => 'D1']);
        $dept2 = Departments::create(['department_name' => 'Dept 2', 'department_code' => 'D2']);

        // Try creating course with same code in Dept 1
        $response1 = $this->postJson('/api/courses', [
            'course_code' => 'IT101',
            'course_name' => 'Intro to IT',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept1->id,
        ]);
        $response1->assertStatus(201);

        // Try creating course with same code in Dept 2
        $response2 = $this->postJson('/api/courses', [
            'course_code' => 'IT101',
            'course_name' => 'IT for Business',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept2->id,
        ]);
        $response2->assertStatus(201);

        // Assert that both exist independently in the database
        $this->assertEquals(2, Course::where('course_code', 'IT101')->count());
    }

    public function test_attaching_course_to_curriculum_does_not_modify_course_globally()
    {
        $user = User::create([
            'name' => 'Secretary User',
            'username' => 'sec_user',
            'email' => 'sec@example.com',
            'password' => bcrypt('password'),
            'role' => 'secretary',
        ]);
        $this->actingAs($user);

        $dept = Departments::create(['department_name' => 'Dept', 'department_code' => 'D']);
        $curr = Curriculum::create([
            'name' => 'Curriculum 1',
            'code' => 'CURR1',
            'department_id' => $dept->id,
            'effective_school_year' => '2026-2027',
            'status' => 'draft',
        ]);

        $course = Course::create([
            'course_code' => 'MATH101',
            'course_name' => 'College Algebra',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'minor',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        // Attach course to curriculum in year 2 semester 2
        $response = $this->postJson("/api/curricula/{$curr->id}/courses", [
            'course_id' => $course->id,
            'year_level' => 2,
            'semester' => 2,
        ]);
        $response->assertStatus(200);

        // Fetch course from DB and check that its global year_level and semester remained unchanged
        $courseFresh = $course->fresh();
        $this->assertEquals('1', $courseFresh->year_level);
        $this->assertEquals('1st', $courseFresh->semester);

        // But checking the pivot table, it must have the correct curriculum-specific mapping
        $pivot = \DB::table('curriculum_course')
            ->where('curriculum_id', $curr->id)
            ->where('course_id', $course->id)
            ->first();

        $this->assertEquals(2, $pivot->year_level);
        $this->assertEquals(2, $pivot->semester);
    }

    public function test_updating_course_does_not_modify_another_department_course_with_same_code()
    {
        $user = User::create([
            'name' => 'VPAA User',
            'username' => 'vpaa_admin3',
            'email' => 'vpaa3@example.com',
            'password' => bcrypt('password'),
            'role' => 'vpaa',
        ]);
        $this->actingAs($user);

        $dept1 = Departments::create(['department_name' => 'IT Dept', 'department_code' => 'IT']);
        $dept2 = Departments::create(['department_name' => 'BA Dept', 'department_code' => 'BA']);

        $course1 = Course::create([
            'course_code' => 'IT104',
            'course_name' => 'Information Technology Fundamentals',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept1->id,
            'status' => 'active',
        ]);

        $course2 = Course::create([
            'course_code' => 'IT104',
            'course_name' => 'Integrative Application Software',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept2->id,
            'status' => 'active',
        ]);

        // Update Course 1 via API
        $response = $this->putJson("/api/courses/{$course1->id}", [
            'course_code' => 'IT104',
            'course_name' => 'Intro to IT Fundas Updated',
            'lecture_hours' => 3,
            'lab_hours' => 1,
            'units' => 4,
            'course_category' => 'major',
            'room_type_required' => 'laboratory',
        ]);
        $response->assertStatus(200);

        // Verify Course 1 was updated
        $this->assertEquals('Intro to IT Fundas Updated', $course1->fresh()->course_name);
        $this->assertEquals(1, $course1->fresh()->lab_hours);

        // Verify Course 2 remains completely unmodified
        $course2Fresh = $course2->fresh();
        $this->assertEquals('Integrative Application Software', $course2Fresh->course_name);
        $this->assertEquals(0, $course2Fresh->lab_hours);
        $this->assertEquals(3, $course2Fresh->units);
    }

    public function test_curriculum_cannot_attach_major_course_from_another_department_with_same_code()
    {
        $user = User::create([
            'name' => 'VPAA User',
            'username' => 'vpaa_admin4',
            'email' => 'vpaa4@example.com',
            'password' => bcrypt('password'),
            'role' => 'secretary',
        ]);
        $this->actingAs($user);

        $itDept = Departments::create(['department_name' => 'IT Dept', 'department_code' => 'IT']);
        $baDept = Departments::create(['department_name' => 'BA Dept', 'department_code' => 'BA']);

        $itCurriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'code' => 'IT-CURR',
            'department_id' => $itDept->id,
            'effective_school_year' => '2026-2027',
            'status' => 'draft',
        ]);

        $baCourse = Course::create([
            'course_code' => 'IT104',
            'course_name' => 'Integrative Application Software',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $baDept->id,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/curricula/{$itCurriculum->id}/courses", [
            'course_id' => $baCourse->id,
            'year_level' => 1,
            'semester' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('curriculum_course', [
            'curriculum_id' => $itCurriculum->id,
            'course_id' => $baCourse->id,
        ]);
    }

    public function test_batch_create_updates_existing_same_department_course_metadata()
    {
        $user = User::create([
            'name' => 'Secretary User',
            'username' => 'sec_user2',
            'email' => 'sec2@example.com',
            'password' => bcrypt('password'),
            'role' => 'secretary',
        ]);
        $this->actingAs($user);

        $dept = Departments::create(['department_name' => 'IT Dept', 'department_code' => 'IT']);
        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum',
            'code' => 'IT-CURR-2',
            'department_id' => $dept->id,
            'effective_school_year' => '2026-2027',
            'status' => 'draft',
        ]);

        $course = Course::create([
            'course_code' => 'IT 117',
            'course_name' => 'Advanced Database System',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '2',
            'semester' => '2nd',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        $curriculum->courses()->attach($course->id, [
            'year_level' => 2,
            'semester' => 3,
        ]);

        $response = $this->postJson("/api/curricula/{$curriculum->id}/courses/batch-create", [
            'courses' => [
                [
                    'row_id' => 'row-1',
                    'course_code' => 'IT 117',
                    'course_name' => 'Qualitative Methods (Modeling & Simulation)',
                    'course_category' => 'major',
                    'lecture_hours' => 3,
                    'lab_hours' => 0,
                    'units' => 3,
                    'year_level' => 2,
                    'semester' => 3,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'success')
            ->assertJsonPath('results.0.course.course_name', 'Qualitative Methods (Modeling & Simulation)');

        $this->assertSame('Qualitative Methods (Modeling & Simulation)', $course->fresh()->course_name);
        $this->assertDatabaseHas('curriculum_course', [
            'curriculum_id' => $curriculum->id,
            'course_id' => $course->id,
            'year_level' => 2,
            'semester' => 3,
        ]);
    }
}
