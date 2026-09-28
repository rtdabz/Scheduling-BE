<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_any_role_can_edit_its_own_name_and_photo_and_the_instructor_record_follows(): void
    {
        $department = $this->department();
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $department->id, 'username' => 'citsec']);
        $faculty = $this->facultyFor($user, $department);

        $this->actingAs($user)
            ->patchJson('/api/profile', [
                'first_name' => 'Maria',
                'middle_initial' => 'c',
                'last_name' => 'Santos',
                'suffix' => 'Jr.',
                'profile_picture' => self::PIXEL,
                // Not editable here; must be ignored.
                'username' => 'hijack',
                'role' => 'vpaa',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Maria C. Santos Jr.')
            ->assertJsonPath('data.user.profile_picture', self::PIXEL);

        $user->refresh();
        $this->assertSame('citsec', $user->username);
        $this->assertSame('secretary', $user->role);
        $faculty->refresh();
        $this->assertSame('Maria', $faculty->first_name);
        $this->assertSame('Santos', $faculty->last_name);
        $this->assertSame(self::PIXEL, $faculty->profile_picture);
    }

    public function test_photo_must_be_an_image_data_url(): void
    {
        $user = User::factory()->create(['role' => 'dean', 'department_id' => $this->department()->id]);

        $this->actingAs($user)
            ->patchJson('/api/profile', [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'profile_picture' => 'https://example.com/photo.png',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');
    }

    public function test_profile_lists_the_instructors_classes_for_the_active_semester(): void
    {
        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create(['room_code' => 'CIT 101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $course = Course::create([
            'course_code' => 'IT 101', 'course_name' => 'Programming', 'lecture_hours' => 3, 'lab_hours' => 0,
            'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1',
            'semester' => '1st', 'department_id' => $department->id, 'status' => 'active',
        ]);
        $section = Sections::create([
            'section_name' => 'BSIT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id,
            'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active',
        ]);
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $faculty = $this->facultyFor($dean, $department);

        foreach (['Monday', 'Thursday'] as $day) {
            Schedule::create([
                'semester_id' => $semester->id, 'section_id' => $section->id, 'course_id' => $course->id,
                'room_id' => $room->id, 'department_id' => $department->id, 'faculty_id' => $faculty->id,
                'day' => $day, 'start_time' => '08:00', 'end_time' => '09:30', 'mode' => 'on-site', 'status' => 'finalized',
            ]);
        }

        $this->actingAs($dean)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.department.department_code', 'CIT')
            ->assertJsonPath('teaching.assigned_units', 3)
            ->assertJsonPath('teaching.basic_load', 15)
            ->assertJsonCount(1, 'teaching.classes')
            ->assertJsonPath('teaching.classes.0.course_code', 'IT 101')
            ->assertJsonCount(2, 'teaching.classes.0.meetings');
    }

    public function test_non_teaching_account_has_no_teaching_section(): void
    {
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department()->id]);

        $this->actingAs($user)->getJson('/api/profile')->assertOk()->assertJsonPath('teaching', null);
    }

    private function department(): Departments
    {
        return Departments::create(['department_name' => 'Information Technology', 'department_code' => 'CIT']);
    }

    private function facultyFor(User $user, Departments $department): Faculty
    {
        return Faculty::create([
            'user_id' => $user->id,
            'first_name' => 'Old',
            'last_name' => 'Name',
            'employment_type' => 'full-time',
            'max_units' => 21,
            'deload_units' => 6,
            'overload_units' => 0,
            'probono_units' => 0,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
    }
}
